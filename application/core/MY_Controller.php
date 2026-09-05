<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/AIWorkforce/autoload.php';

/**
 * Base controller: builds the AI Workforce Platform (domain services wired to the
 * database through CI3's model layer) once per request.
 */
class MY_Controller extends CI_Controller
{
    public \AIWorkforce\Platform $platform;

    /** Per-request guard: permissions are re-read from the database at most once. */
    private bool $identityPermissionsRefreshed = false;

    /** The identity as refreshed during this request (permissions from the database). */
    private ?array $refreshedIdentity = null;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('AIWorkforce_model');
        $disableReal = getenv('AI_WORKFORCE_DISABLE_REAL_PROVIDERS') === '1';
        $this->platform = new \AIWorkforce\Platform($this->AIWorkforce_model, $disableReal);
    }

    /**
     * Re-read the signed-in identity's permissions from the database and refresh
     * the session snapshot before a permission decision is taken.
     *
     * The session identity is a point-in-time copy captured at sign-in, so a
     * role change made afterwards (admin console, RBAC re-seed, support
     * escalation) used to be invisible until the next sign-in — leaving
     * operators staring at "lacks sports.manage" refusals on an account that
     * does hold the role. Reading fresh here makes grants take effect on the
     * very next action and makes a revoked permission stop working at once
     * instead of surviving in the session until logout.
     *
     * Fail-soft on a database error: the snapshot the session was issued with
     * is kept rather than escalating an outage into a permission change.
     *
     * @return array|null the identity with current permissions, or null when nobody is signed in
     */
    protected function refreshIdentityPermissions(?array $user = null): ?array
    {
        if ($user === null) {
            $session = $this->session->userdata('identity');
            $user = is_array($session) ? $session : null;
        }
        if ($user === null || empty($user['id'])) return null;
        // Later callers in the same request get the already-refreshed copy, so
        // a stale array handed in by the caller can never win over the fresh one.
        if ($this->identityPermissionsRefreshed) return $this->refreshedIdentity ?? $user;
        $this->identityPermissionsRefreshed = true;
        try {
            $permissions = $this->AIWorkforce_model->identity->permissionsForUser((int) $user['id']);
        } catch (\Throwable $e) {
            log_message('error', 'refreshIdentityPermissions failed: ' . $e->getMessage());
            return $this->refreshedIdentity = $user;
        }
        if (!is_array($permissions)) return $this->refreshedIdentity = $user;
        sort($permissions);
        $current = array_values((array) ($user['permissions'] ?? []));
        sort($current);
        if ($permissions !== $current) {
            $user['permissions'] = $permissions;
            $this->session->set_userdata('identity', $user);
        }
        return $this->refreshedIdentity = $user;
    }

    protected function currentUser(): ?array
    {
        $user = $this->session->userdata('identity');
        if (is_array($user) && !empty($user['id'])) return $user;
        return $this->restoreFromRememberCookie();
    }

    /**
     * Remember-me support: when no session identity exists, a valid signed
     * cookie (issued by Auth::login when "remember me" was checked) restores
     * the session transparently. The cookie is stateless — userId + expiry +
     * HMAC signed with the configured encryption key — so it fails closed
     * when no key is configured, when the signature/expiry is wrong, or when
     * the account was deactivated.
     */
    protected function restoreFromRememberCookie(): ?array
    {
        $key = (string) $this->config->item('encryption_key');
        if ($key === '') return null; // fail closed without a signing key
        $raw = (string) ($this->input->cookie(self::REMEMBER_COOKIE, true) ?: ($_COOKIE[self::REMEMBER_COOKIE] ?? ''));
        if ($raw === '' || substr_count($raw, '.') !== 3) return null;
        [$version, $id, $expires, $sig] = explode('.', $raw, 4);
        if ($version !== 'v1' || !ctype_digit($id) || !ctype_digit($expires)) return null;
        if ((int) $expires < time()) return null;
        $expected = hash_hmac('sha256', "v1.{$id}.{$expires}", $key);
        if (!hash_equals($expected, $sig)) return null;
        $user = $this->platform->identity->rememberUser((int) $id);
        if (!$user) return null;
        $this->session->set_userdata([
            'identity' => $user,
            'csrf_token' => (string) ($this->session->userdata('csrf_token') ?: bin2hex(random_bytes(32))),
        ]);
        return $user;
    }

    /** Issue the signed remember-me cookie (30 days, HttpOnly, SameSite=Lax). */
    protected function issueRememberCookie(int $userId): void
    {
        $key = (string) $this->config->item('encryption_key');
        if ($key === '') return; // feature disabled without a signing key
        $expires = time() + 30 * 86400;
        $sig = hash_hmac('sha256', "v1.{$userId}.{$expires}", $key);
        setcookie(self::REMEMBER_COOKIE, "v1.{$userId}.{$expires}.{$sig}", [
            'expires' => $expires,
            'path' => '/',
            'domain' => (string) $this->config->item('cookie_domain'),
            'secure' => (bool) $this->config->item('cookie_secure'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** Clear the remember-me cookie (logout, credential changes). */
    protected function clearRememberCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => (string) $this->config->item('cookie_domain'),
            'secure' => (bool) $this->config->item('cookie_secure'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public const REMEMBER_COOKIE = 'ai_workforce_remember';

    protected function isSuperAdmin(?array $user = null): bool
    {
        $user = $user ?? $this->currentUser();
        return $user !== null && $this->platform->identity->can($user, 'system.super_admin');
    }

    protected function canAccessAdmin(?array $user = null): bool
    {
        $user = $user ?? $this->currentUser();
        return $user !== null && $this->platform->identity->canAccessAdmin($user);
    }

    /** Any administrator portal role — used for login routing and chrome. */
    protected function isAdmin(?array $user = null): bool
    {
        return $this->canAccessAdmin($user);
    }

    protected function impersonator(): ?array
    {
        $admin = $this->session->userdata('impersonator');
        return is_array($admin) && !empty($admin['id']) ? $admin : null;
    }

    /**
     * Administrator who is actually in control. Super Admin keeps full portal
     * access while viewing another account's dashboard.
     */
    protected function adminActor(?array $user = null): ?array
    {
        $impersonator = $this->impersonator();
        if ($impersonator && $this->isSuperAdmin($impersonator)) {
            return $impersonator;
        }
        return $user ?? $this->currentUser();
    }

    protected function requireLogin(): array
    {
        $user = $this->currentUser();
        if ($user) {
            if (!$this->impersonator()) {
                $fresh = $this->AIWorkforce_model->identity->findUserById((int) $user['id']);
                if (!$fresh || empty($fresh['active'])) {
                    $this->session->unset_userdata(['identity']);
                    $this->session->set_flashdata('error', 'Your account is currently unavailable. Please contact support.');
                    redirect('/login');
                    exit;
                }
            }
            return $user;
        }
        $next = '/' . ltrim((string) uri_string(), '/');
        if ($next !== '/' && $next !== '') $this->session->set_userdata('return_to', $next);
        redirect('/login');
        exit;
    }

    protected function requireAdminPage(): array
    {
        // Portal access follows the stored roles, not the sign-in snapshot.
        $user = $this->refreshIdentityPermissions($this->currentUser()) ?? $this->currentUser();
        if (!$user) {
            $this->session->set_userdata('return_to', '/admin');
            redirect('/admin/login');
            exit;
        }
        $actor = $this->adminActor($user);
        if ($this->impersonator() && !$this->isSuperAdmin($this->impersonator())) {
            redirect('/dashboard');
            exit;
        }
        if (!$actor || !$this->canAccessAdmin($actor)) {
            redirect('/access-denied');
            exit;
        }
        return $actor;
    }

    /** Server-side permission gate for individual admin actions. */
    protected function requireAdminPermission(string $permission): ?array
    {
        $user = $this->requireAdminPage();
        if (!$this->platform->identity->can($user, $permission)) {
            $this->session->set_flashdata('error', 'You do not have permission to perform that action.');
            redirect('/admin');
            return null;
        }
        return $user;
    }

    /** Require a signed-in user plus an explicit permission for privileged APIs. */
    protected function requirePermission(string $permission, bool $csrf = true): ?array
    {
        // Permissions come from the database, not from the sign-in snapshot.
        $user = $this->refreshIdentityPermissions();
        if (!is_array($user) || !$this->platform->identity->can($user, $permission)) {
            $this->jsonError('forbidden', 403); return null;
        }
        if ($csrf && !in_array($this->input->method(true), ['GET', 'HEAD'], true)) {
            $token = $this->input->get_request_header('X-CSRF-Token');
            if (!is_string($token) || !hash_equals((string) $this->session->userdata('csrf_token'), $token)) {
                $this->jsonError('invalid CSRF token', 403); return null;
            }
        }
        return $user;
    }

    /** JSON response helper. */
    protected function json($data, int $status = 200): void
    {
        http_response_code($status);
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function jsonError(string $message, int $status = 400, array $extra = []): void
    {
        $this->json(array_merge(['error' => $message], $extra), $status);
    }

    /** Read + validate a JSON request body. */
    protected function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return $this->input->post() ?: [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

/** JSON API base — always returns JSON, never a view. */
class Api_controller extends MY_Controller
{
    private const PUBLIC_ACTIONS = [
        'api_auth/login' => true,
        'api_chat/respond' => true,
        'api_system/status' => true,
        'api_system/features' => true,
        // Lottery Intelligence — public READ-only endpoints (historical data only, no mutations)
        'api_lottery/status' => true,
        'api_lottery/dashboard' => true,
        'api_lottery/lotteries' => true,
        'api_lottery/rules' => true,
        'api_lottery/draws' => true,
        'api_lottery/statistics' => true,
        'api_lottery/analyze' => true,
        'api_lottery/combinations' => true,
        'api_lottery/system' => true,
        'api_lottery/backtests' => true,
        'api_lottery/models' => true,
        'api_lottery/performance' => true,
        'api_lottery/providers' => true,
        'api_lottery/health' => true,
        'api_lottery/jobs' => true,
        // Football Intelligence — the provider-state endpoint only. It exposes
        // statuses (NOT_CONFIGURED / ONLINE, counts, cadence) and never a key,
        // so a dashboard can explain why it is empty without a session.
        'api_football/status' => true,
        'api_football/provider_status' => true,
    ];

    public function __construct()
    {
        parent::__construct();
        $key = strtolower($this->router->class . '/' . $this->router->method);
        if (!isset(self::PUBLIC_ACTIONS[$key]) && $this->currentUser() === null) {
            $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
            $wantsHtml = str_contains($accept, 'text/html') && !str_contains($accept, 'application/json');
            if ($wantsHtml && in_array($this->input->method(true), ['GET', 'HEAD'], true)) {
                $next = '/' . ltrim((string) uri_string(), '/');
                $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
                if ($qs !== '') $next .= '?' . $qs;
                $this->session->set_userdata('return_to', $next);
                redirect('/login');
                exit;
            }
            $this->jsonError('unauthenticated', 401);
            $this->output->_display();
            exit;
        }
    }
    protected function requireFields(array $body, array $fields): ?array
    {
        $missing = [];
        foreach ($fields as $f) {
            if (!isset($body[$f]) || $body[$f] === '' || $body[$f] === null) $missing[] = $f;
        }
        if ($missing) {
            $this->jsonError('missing required fields: ' . implode(', ', $missing), 400);
            return null;
        }
        return $body;
    }
}

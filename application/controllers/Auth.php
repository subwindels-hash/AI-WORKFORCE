<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Browser authentication pages for the portable PHP/cPanel application. */
class Auth extends MY_Controller
{
    public function index()
    {
        if ($user = $this->sessionUser()) {
            redirect($this->isAdmin($user) ? '/admin' : '/dashboard');
            return;
        }
        $this->renderAuth(false);
    }

    public function admin_login()
    {
        if ($user = $this->sessionUser()) {
            redirect($this->isAdmin($user) ? '/admin' : '/access-denied');
            return;
        }
        $this->renderAuth(true);
    }

    public function register()
    {
        if ($this->sessionUser()) { redirect('/dashboard'); return; }
        $this->load->view('auth/register', [
            'title' => 'Create an account',
            'error' => $this->consumeFlash('error'),
            'notice' => $this->consumeFlash('notice'),
            'csrfToken' => $this->ensureVisitorCsrf(),
        ]);
    }

    public function register_submit()
    {
        if ($this->sessionUser()) { redirect('/dashboard'); return; }
        if (!$this->validAuthCsrf()) {
            $this->flash('error', 'Your session expired while filling in the form. Please try again.');
            redirect('/register');
            return;
        }
        $email = strtolower(trim((string) $this->input->post('email')));
        $name = trim((string) $this->input->post('display_name'));
        $password = (string) $this->input->post('password');
        $confirm = (string) $this->input->post('password_confirm');
        $terms = (string) $this->input->post('terms');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || strlen($name) > 120) {
            $this->flash('error', 'Enter your name and a valid email address.');
            redirect('/register');
            return;
        }
        if (strlen($password) < 12 || $password !== $confirm) {
            $this->flash('error', 'Use a password of at least 12 characters and confirm it exactly.');
            redirect('/register');
            return;
        }
        if ($terms !== '1') {
            $this->flash('error', 'Please accept the Terms and Privacy Policy to create an account.');
            redirect('/register');
            return;
        }
        if ($this->Aegis_model->identity->findUserByEmail($email)) {
            $this->flash('error', 'An account with that email already exists. Sign in instead.');
            redirect('/login');
            return;
        }
        $now = gmdate('c');
        $new = $this->Aegis_model->identity->createUser([
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => $name,
            'active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'last_login_at' => $now,
        ]);
        $role = $this->Aegis_model->identity->ensureRole('platform_member', 'Platform member');
        foreach (['trading.view', 'sports.view', 'lottery.view'] as $code) {
            $pid = $this->Aegis_model->identity->ensurePermission($code, $code);
            $this->Aegis_model->identity->grantRolePermission($role, $pid);
        }
        $this->Aegis_model->identity->assignRole((int) $new['id'], $role);
        $this->Aegis_model->audit->emit('USER_REGISTERED', 'A visitor created a platform member account', ['userId' => (int) $new['id']], 'visitor');
        $user = $this->platform->identity->authenticate($email, $password);
        if (!$user) { $this->flash('error', 'Account created. Sign in to continue.'); redirect('/login'); return; }
        $this->establishSession($user);
        redirect('/dashboard');
    }

    public function forgot()
    {
        $this->load->view('auth/forgot', [
            'title' => 'Reset password',
            'notice' => $this->consumeFlash('notice'),
            'csrfToken' => $this->ensureVisitorCsrf(),
        ]);
    }

    public function forgot_submit()
    {
        if (!$this->validAuthCsrf()) {
            $this->flash('error', 'Your session expired. Please try again.');
            redirect('/forgot-password'); return;
        }
        $this->flash('notice', 'Password resets are issued by an administrator. Email support or your platform admin — this form does not invent a reset token.');
        redirect('/forgot-password');
    }

    public function denied()
    {
        $this->load->view('auth/denied', [
            'title' => 'Access denied',
            'user' => $this->sessionUser(),
        ]);
    }

    public function login()
    {
        $admin = $this->input->post('admin') === '1';
        if (!$this->validAuthCsrf()) { $this->flash('error', 'Your session expired. Please try again.'); $this->redirectLogin($admin); return; }
        $email = strtolower(trim((string) $this->input->post('email')));
        $password = (string) $this->input->post('password');
        $remember = $this->input->post('remember') === '1';
        $attempts = (int) $this->session->userdata('login_attempts');
        $until = (int) $this->session->userdata('login_locked_until');
        if ($until > time()) { $this->flash('error', 'Too many attempts. Try again later.'); $this->redirectLogin($admin); return; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') { $this->flash('error', 'Enter a valid email and password.'); $this->redirectLogin($admin); return; }
        $user = $this->platform->identity->authenticate($email, $password);
        if (!$user || ($admin && !$this->platform->identity->can($user, 'system.super_admin'))) {
            $attempts++;
            $this->session->set_userdata('login_attempts', $attempts);
            if ($attempts >= 5) $this->session->set_userdata('login_locked_until', time() + 900);
            $this->flash('error', $admin ? 'Administrator access was not granted.' : 'Invalid email or password.');
            $this->redirectLogin($admin); return;
        }
        $this->establishSession($user);
        // "Remember me" keeps the user signed in on this browser for 30 days
        // via a signed, HttpOnly cookie; the session itself stays short-lived.
        if ($remember) $this->issueRememberCookie((int) $user['id']);
        else $this->clearRememberCookie();
        $next = (string) $this->session->userdata('return_to');
        $this->session->unset_userdata('return_to');
        if ($admin || $this->isAdmin($user)) { redirect('/admin'); return; }
        if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//') && !str_contains($next, '://')) {
            redirect($next); return;
        }
        redirect('/dashboard');
    }

    public function logout()
    {
        $identity = $this->sessionUser();
        if ($identity) {
            $token = (string) $this->input->post('csrf_token');
            $known = (string) $this->session->userdata('csrf_token');
            if ($token === '' || $known === '' || !hash_equals($known, $token)) { $this->flash('error', 'Invalid security token.'); redirect('/account'); return; }
        }
        $this->clearRememberCookie();
        $this->session->sess_destroy(); redirect('/login');
    }

    public function account()
    {
        $user = $this->requireLogin();
        $this->renderPage('Account', 'account', ['user' => $user]);
    }

    private function establishSession(array $user): void
    {
        $this->session->sess_regenerate(true);
        $this->session->set_userdata(['identity' => $user, 'csrf_token' => bin2hex(random_bytes(32)), 'login_attempts' => 0, 'login_locked_until' => 0]);
    }

    private function renderAuth(bool $admin): void
    {
        $this->load->view('auth/login', [
            'title' => $admin ? 'Administrator sign in' : 'User sign in',
            'admin' => $admin,
            'error' => $this->consumeFlash('error'),
            'notice' => $this->consumeFlash('notice'),
            'csrfToken' => $this->ensureVisitorCsrf(),
        ]);
    }

    /** CSRF for signed-out visitors: mint one for the session on first render. */
    private function ensureVisitorCsrf(): string
    {
        $token = (string) $this->session->userdata('csrf_token');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set_userdata('csrf_token', $token);
        }
        return $token;
    }

    /** Verify the csrf_token posted by an auth form against the session. */
    private function validAuthCsrf(): bool
    {
        $token = (string) $this->input->post('csrf_token');
        $known = (string) $this->session->userdata('csrf_token');
        return $token !== '' && $known !== '' && hash_equals($known, $token);
    }

    private function renderPage(string $title, string $active, array $data = []): void
    {
        $state = $this->platform->state();
        $data = array_merge($data, [
            'title' => $title, 'active' => $active,
            'status' => ['tradingMode' => $state['tradingMode'], 'killSwitch' => $state['killSwitch'], 'providers' => $this->platform->providers->getAllHealth()],
            'notice' => $this->consumeFlash('notice'), 'error' => $this->consumeFlash('error'),
        ]);
        $this->load->view('layout/header', $data); $this->load->view('auth/account', $data); $this->load->view('layout/footer');
    }

    private function sessionUser(): ?array { return $this->currentUser(); }
    private function redirectLogin(bool $admin): void { redirect($admin ? '/admin/login' : '/login'); }
    private function flash(string $key, string $value): void { $this->session->set_flashdata($key, $value); }
    private function consumeFlash(string $key): ?string { $value = $this->session->flashdata($key); return is_string($value) && $value !== '' ? $value : null; }
}

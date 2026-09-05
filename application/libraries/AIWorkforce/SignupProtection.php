<?php
namespace AIWorkforce;

/**
 * Sign-up protection: email validation + reCAPTCHA, configured from the
 * super-admin System Settings page (category `signup`).
 *
 * Both checks are enforced server-side in Auth::register_submit; the
 * browser-side widget/regex is a convenience layer only. Everything fails
 * closed: a captcha that cannot be verified is a captcha that failed.
 *
 * Secrets: the reCAPTCHA secret is stored sealed (AES-256-GCM via
 * ApiProviders::seal) in platform_settings and is never returned to a view or
 * an API. Environment variables VP_RECAPTCHA_SITE_KEY / VP_RECAPTCHA_SECRET
 * take precedence over stored values so cPanel deployments can keep the
 * secret out of the database entirely.
 */
final class SignupProtection
{
    public const CATEGORY = 'signup';

    public const PROVIDER_OFF = 'off';
    public const PROVIDER_RECAPTCHA_V2 = 'recaptcha_v2';
    public const PROVIDER_RECAPTCHA_V3 = 'recaptcha_v3';
    public const PROVIDERS = [self::PROVIDER_OFF, self::PROVIDER_RECAPTCHA_V2, self::PROVIDER_RECAPTCHA_V3];

    public const EMAIL_MODE_SYNTAX = 'syntax';
    public const EMAIL_MODE_MX = 'mx';
    public const EMAIL_MODES = [self::EMAIL_MODE_SYNTAX, self::EMAIL_MODE_MX];

    public const SITEVERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    public const CAPTCHA_FIELD = 'g-recaptcha-response';
    public const HONEYPOT_FIELD = 'website_url';
    public const ACTION = 'register';

    public const DEFAULTS = [
        'email_validation_mode' => self::EMAIL_MODE_MX,
        'email_block_disposable' => '1',
        'email_blocked_domains' => '',
        'email_allowed_domains' => '',
        'captcha_provider' => self::PROVIDER_OFF,
        'captcha_site_key' => '',
        'captcha_secret' => '',        // sealed blob, never plaintext
        'captcha_min_score' => '0.5',
    ];

    /** @var callable|null test seam: fn(string $domain): bool — does the domain accept mail? */
    public static $dnsResolver = null;

    /**
     * Build from the stored settings (a settingsByCategory()['signup'] slice or
     * the AdminPortal itself) merged with environment overrides.
     */
    public static function fromSettings(array $settings): self
    {
        $s = array_merge(self::DEFAULTS, array_intersect_key($settings, self::DEFAULTS));
        $envSite = trim((string) (getenv('VP_RECAPTCHA_SITE_KEY') ?: ''));
        $envSecret = trim((string) (getenv('VP_RECAPTCHA_SECRET') ?: ''));
        $secret = $envSecret !== '' ? $envSecret : ApiProviders::open($s['captcha_secret'] !== '' ? (string) $s['captcha_secret'] : null);
        return new self(
            in_array($s['email_validation_mode'], self::EMAIL_MODES, true) ? $s['email_validation_mode'] : self::EMAIL_MODE_MX,
            (string) $s['email_block_disposable'] === '1',
            self::parseDomainList((string) $s['email_blocked_domains']),
            self::parseDomainList((string) $s['email_allowed_domains']),
            in_array($s['captcha_provider'], self::PROVIDERS, true) ? $s['captcha_provider'] : self::PROVIDER_OFF,
            $envSite !== '' ? $envSite : trim((string) $s['captcha_site_key']),
            $secret,
            self::clampScore((string) $s['captcha_min_score']),
            $envSite !== '' || $envSecret !== ''
        );
    }

    public static function fromPortal(AdminPortal $portal): self
    {
        $all = $portal->settingsByCategory();
        return self::fromSettings(is_array($all[self::CATEGORY] ?? null) ? $all[self::CATEGORY] : []);
    }

    public function __construct(
        public readonly string $emailMode,
        public readonly bool $blockDisposable,
        /** @var string[] */ public readonly array $blockedDomains,
        /** @var string[] */ public readonly array $allowedDomains,
        public readonly string $captchaProvider,
        public readonly string $siteKey,
        private readonly string $secret,
        public readonly float $minScore,
        public readonly bool $fromEnv = false,
    ) {}

    // ─── Email ──────────────────────────────────────────────────────────────

    /**
     * Validate + normalise an address. Never fabricates: an address the
     * domain-level checks cannot vouch for is rejected with a reason code.
     *
     * @return array{ok:bool, email:string, domain:string, reason:string, message:string}
     */
    public function validateEmail(string $raw): array
    {
        $email = strtolower(trim($raw));
        $email = rtrim($email, '.');
        $fail = static fn(string $reason, string $message) => ['ok' => false, 'email' => $email, 'domain' => '', 'reason' => $reason, 'message' => $message];

        if ($email === '' || strlen($email) > 190 || substr_count($email, '@') !== 1) {
            return $fail('SYNTAX', 'Enter a valid email address.');
        }
        [$local, $domain] = explode('@', $email, 2);
        if ($local === '' || strlen($local) > 64 || $domain === '' || strlen($domain) > 253) {
            return $fail('SYNTAX', 'Enter a valid email address.');
        }
        // Reject the things filter_var lets through that no mailbox provider accepts.
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || str_starts_with($local, '.') || str_ends_with($local, '.') || str_contains($local, '..')
            || !str_contains($domain, '.') || str_starts_with($domain, '-') || str_contains($domain, '..')
            || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,63}$/', $domain)
            || preg_match('/[^a-z0-9!#$%&\'*+\/=?^_`{|}~.-]/', $local)) {
            return $fail('SYNTAX', 'Enter a valid email address (for example name@example.com).');
        }
        $out = ['ok' => true, 'email' => $email, 'domain' => $domain, 'reason' => 'OK', 'message' => ''];

        if ($this->allowedDomains !== [] && !self::domainMatches($domain, $this->allowedDomains)) {
            return $fail('DOMAIN_NOT_ALLOWED', 'Registration is limited to approved email domains. Use your organisation email address.');
        }
        if (self::domainMatches($domain, $this->blockedDomains)) {
            return $fail('DOMAIN_BLOCKED', 'Email addresses from that domain cannot be used to register.');
        }
        if ($this->blockDisposable && self::isDisposable($domain)) {
            return $fail('DISPOSABLE', 'Temporary or disposable email addresses cannot be used. Please use a permanent address.');
        }
        if ($this->emailMode === self::EMAIL_MODE_MX && !self::domainAcceptsMail($domain)) {
            return $fail('NO_MX', 'That email domain does not accept mail. Check the spelling of the address.');
        }
        return $out;
    }

    /** True when the domain (or a parent domain) is in the list. */
    public static function domainMatches(string $domain, array $list): bool
    {
        if ($list === []) return false;
        $parts = explode('.', $domain);
        for ($i = 0; $i < count($parts) - 1; $i++) {
            if (in_array(implode('.', array_slice($parts, $i)), $list, true)) return true;
        }
        return false;
    }

    public static function isDisposable(string $domain): bool
    {
        return self::domainMatches(strtolower($domain), self::DISPOSABLE_DOMAINS);
    }

    /** MX record — or, per RFC 5321 §5.1, an A/AAAA fallback. Overridable for tests. */
    public static function domainAcceptsMail(string $domain): bool
    {
        if (is_callable(self::$dnsResolver)) return (bool) (self::$dnsResolver)($domain);
        if (!function_exists('checkdnsrr')) return true; // cannot verify → do not block on it
        $host = rtrim($domain, '.') . '.';
        try {
            return @checkdnsrr($host, 'MX') || @checkdnsrr($host, 'A') || @checkdnsrr($host, 'AAAA');
        } catch (\Throwable $e) {
            return true;
        }
    }

    /** One domain per line (or comma separated), lower-cased, validated, de-duplicated. */
    public static function parseDomainList(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', strtolower($text)) ?: [] as $d) {
            $d = trim($d, " \t.@");
            if ($d === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,63}$/', $d)) continue;
            $out[$d] = true;
        }
        return array_keys($out);
    }

    // ─── Captcha ────────────────────────────────────────────────────────────

    public function captchaEnabled(): bool
    {
        return $this->captchaProvider !== self::PROVIDER_OFF;
    }

    /** Enabled but missing a key: the page must refuse rather than render a broken widget. */
    public function captchaMisconfigured(): bool
    {
        return $this->captchaEnabled() && ($this->siteKey === '' || $this->secret === '');
    }

    public function secretConfigured(): bool { return $this->secret !== ''; }

    /** Data safe to hand to a view: never the secret. */
    public function widget(): array
    {
        return [
            'provider' => $this->captchaProvider,
            'enabled' => $this->captchaEnabled(),
            'misconfigured' => $this->captchaMisconfigured(),
            'siteKey' => $this->siteKey,
            'field' => self::CAPTCHA_FIELD,
            'action' => self::ACTION,
            'honeypot' => self::HONEYPOT_FIELD,
        ];
    }

    /**
     * Verify the token posted by the widget with Google's siteverify endpoint.
     * Fails closed on every non-success path (missing token, transport error,
     * unparseable body, wrong action, low score).
     *
     * @return array{ok:bool, reason:string, message:string, score:?float, hostname:?string, errorCodes:string[]}
     */
    public function verifyCaptcha(?string $token, string $remoteIp = ''): array
    {
        $res = static fn(bool $ok, string $reason, string $message = '', array $extra = []) => array_merge(
            ['ok' => $ok, 'reason' => $reason, 'message' => $message, 'score' => null, 'hostname' => null, 'errorCodes' => []],
            $extra
        );
        if (!$this->captchaEnabled()) return $res(true, 'DISABLED');
        if ($this->captchaMisconfigured()) {
            return $res(false, 'MISCONFIGURED', 'Registration is temporarily unavailable: the sign-up verification service is not fully configured. Please try again later.');
        }
        $token = trim((string) $token);
        if ($token === '' || strlen($token) > 4096) {
            return $res(false, 'MISSING_TOKEN', $this->captchaProvider === self::PROVIDER_RECAPTCHA_V2
                ? 'Please tick the "I\'m not a robot" box before creating your account.'
                : 'We could not verify that you are human. Please reload the page and try again.');
        }
        $body = http_build_query(array_filter([
            'secret' => $this->secret,
            'response' => $token,
            'remoteip' => $remoteIp !== '' ? $remoteIp : null,
        ]));
        try {
            $resp = ApiProviders::http(self::SITEVERIFY_URL, ['Content-Type: application/x-www-form-urlencoded'], $body);
        } catch (\Throwable $e) {
            log_message('error', 'recaptcha siteverify transport failure: ' . $e->getMessage());
            return $res(false, 'UNREACHABLE', 'We could not verify that you are human right now. Please try again in a moment.');
        }
        $status = (int) ($resp['status'] ?? 0);
        $decoded = json_decode((string) ($resp['body'] ?? ''), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            log_message('error', 'recaptcha siteverify HTTP ' . $status . ' — unparseable or non-2xx response');
            return $res(false, 'UNREACHABLE', 'We could not verify that you are human right now. Please try again in a moment.');
        }
        $codes = array_values(array_map('strval', (array) ($decoded['error-codes'] ?? [])));
        $extra = ['hostname' => isset($decoded['hostname']) ? (string) $decoded['hostname'] : null, 'errorCodes' => $codes, 'score' => isset($decoded['score']) ? (float) $decoded['score'] : null];
        if (empty($decoded['success'])) {
            if (in_array('invalid-input-secret', $codes, true) || in_array('missing-input-secret', $codes, true)) {
                log_message('error', 'recaptcha: the configured secret key was rejected by Google (' . implode(',', $codes) . ')');
                return $res(false, 'BAD_SECRET', 'Registration is temporarily unavailable: the sign-up verification service rejected its configuration. Please try again later.', $extra);
            }
            if (in_array('timeout-or-duplicate', $codes, true)) {
                return $res(false, 'EXPIRED', 'The verification expired. Please complete it again and resubmit.', $extra);
            }
            return $res(false, 'FAILED', 'Human verification failed. Please try again.', $extra);
        }
        if ($this->captchaProvider === self::PROVIDER_RECAPTCHA_V3) {
            $action = (string) ($decoded['action'] ?? '');
            if ($action !== '' && $action !== self::ACTION) {
                return $res(false, 'WRONG_ACTION', 'Human verification failed. Please reload the page and try again.', $extra);
            }
            $score = (float) ($decoded['score'] ?? 0.0);
            if ($score < $this->minScore) {
                return $res(false, 'LOW_SCORE', 'We could not confirm that this request is from a person. Please try again, or contact support if the problem continues.', $extra);
            }
        }
        return $res(true, 'OK', '', $extra);
    }

    /** Honeypot: the field is invisible to people, so any value means automation. */
    public static function honeypotTripped(?string $value): bool
    {
        return trim((string) $value) !== '';
    }

    // ─── Settings normalisation (used by Admin::settings_save) ──────────────

    /**
     * Turn a posted form into the values to store. The secret is sealed here;
     * blank keeps the existing sealed value, `$clearSecret` removes it.
     *
     * @return array{values:array<string,string>, warnings:string[]}
     */
    public static function normalizeSettings(array $post, string $existingSealedSecret, bool $clearSecret = false): array
    {
        $warnings = [];
        $values = [];
        $values['email_validation_mode'] = in_array($post['email_validation_mode'] ?? '', self::EMAIL_MODES, true) ? (string) $post['email_validation_mode'] : self::EMAIL_MODE_MX;
        $values['email_block_disposable'] = ($post['email_block_disposable'] ?? '') === '1' ? '1' : '0';
        $values['email_blocked_domains'] = implode("\n", self::parseDomainList((string) ($post['email_blocked_domains'] ?? '')));
        $values['email_allowed_domains'] = implode("\n", self::parseDomainList((string) ($post['email_allowed_domains'] ?? '')));
        $provider = (string) ($post['captcha_provider'] ?? self::PROVIDER_OFF);
        $values['captcha_provider'] = in_array($provider, self::PROVIDERS, true) ? $provider : self::PROVIDER_OFF;
        $siteKey = trim((string) ($post['captcha_site_key'] ?? ''));
        $values['captcha_site_key'] = preg_match('/^[A-Za-z0-9_-]{10,200}$/', $siteKey) ? $siteKey : '';
        if ($siteKey !== '' && $values['captcha_site_key'] === '') $warnings[] = 'The site key was ignored: it must be the key Google shows in the reCAPTCHA admin console (letters, numbers, - and _ only).';
        $values['captcha_min_score'] = (string) self::clampScore((string) ($post['captcha_min_score'] ?? '0.5'));

        $secret = trim((string) ($post['captcha_secret'] ?? ''));
        if ($clearSecret) {
            $values['captcha_secret'] = '';
        } elseif ($secret !== '') {
            if (!preg_match('/^[A-Za-z0-9_-]{10,200}$/', $secret)) {
                $warnings[] = 'The secret key was ignored: it must be the secret Google shows in the reCAPTCHA admin console.';
                $values['captcha_secret'] = $existingSealedSecret;
            } else {
                $values['captcha_secret'] = ApiProviders::seal($secret);
            }
        } else {
            $values['captcha_secret'] = $existingSealedSecret;
        }

        $envSite = trim((string) (getenv('VP_RECAPTCHA_SITE_KEY') ?: ''));
        $envSecret = trim((string) (getenv('VP_RECAPTCHA_SECRET') ?: ''));
        if ($values['captcha_provider'] !== self::PROVIDER_OFF) {
            if ($values['captcha_site_key'] === '' && $envSite === '') $warnings[] = 'reCAPTCHA is switched on but no site key is set — sign-up is blocked until you add one.';
            if ($values['captcha_secret'] === '' && $envSecret === '') $warnings[] = 'reCAPTCHA is switched on but no secret key is set — sign-up is blocked until you add one.';
        }
        if ($values['email_allowed_domains'] !== '') $warnings[] = 'An allow-list is active: only the listed email domains can register.';
        return ['values' => $values, 'warnings' => $warnings];
    }

    /** Operator-facing status (no secrets) for the settings page / dashboard card. */
    public function status(): array
    {
        $captcha = match (true) {
            !$this->captchaEnabled() => 'OFF',
            $this->captchaMisconfigured() => 'MISCONFIGURED',
            default => 'ACTIVE',
        };
        return [
            'captchaProvider' => $this->captchaProvider,
            'captchaLabel' => self::providerLabel($this->captchaProvider),
            'captchaState' => $captcha,
            'siteKeyConfigured' => $this->siteKey !== '',
            'secretConfigured' => $this->secret !== '',
            'keysFromEnv' => $this->fromEnv,
            'minScore' => $this->minScore,
            'emailMode' => $this->emailMode,
            'blockDisposable' => $this->blockDisposable,
            'blockedDomains' => count($this->blockedDomains),
            'allowedDomains' => count($this->allowedDomains),
            'signupBlocked' => $captcha === 'MISCONFIGURED',
        ];
    }

    public static function providerLabel(string $provider): string
    {
        return match ($provider) {
            self::PROVIDER_RECAPTCHA_V2 => 'reCAPTCHA v2 (checkbox)',
            self::PROVIDER_RECAPTCHA_V3 => 'reCAPTCHA v3 (invisible, score)',
            default => 'Off',
        };
    }

    private static function clampScore(string $raw): float
    {
        $v = is_numeric($raw) ? (float) $raw : 0.5;
        return round(max(0.1, min(0.9, $v)), 1);
    }

    /**
     * Well-known disposable / throw-away mail domains. Admins extend this via
     * the blocked-domains list; the switch turns the built-in list off.
     */
    public const DISPOSABLE_DOMAINS = [
        '10minutemail.com', '10minutemail.net', '10mail.org', '1secmail.com', '1secmail.net', '1secmail.org', '20minutemail.com', '33mail.com',
        'anonbox.net', 'burnermail.io', 'byom.de', 'crazymailing.com', 'discard.email', 'dispostable.com', 'dropmail.me', 'emailfake.com',
        'emailondeck.com', 'fakeinbox.com', 'fakemail.net', 'getairmail.com', 'getnada.com', 'grr.la', 'guerrillamail.com', 'guerrillamail.net',
        'guerrillamail.org', 'guerrillamail.biz', 'guerrillamail.de', 'guerrillamailblock.com', 'harakirimail.com', 'inboxbear.com',
        'incognitomail.com', 'jetable.org', 'luxusmail.org', 'mail-temp.com', 'mailcatch.com', 'maildrop.cc', 'mailexpire.com', 'mailforspam.com',
        'mailinator.com', 'mailinator.net', 'mailnesia.com', 'mailnull.com', 'mailsac.com', 'mailtemp.info', 'meltmail.com', 'minutemail.com',
        'mintemail.com', 'moakt.com', 'mohmal.com', 'mytemp.email', 'nowmymail.com', 'objectmail.com', 'pokemail.net', 'proxymail.eu', 'rcpt.at',
        'sharklasers.com', 'spam4.me', 'spambog.com', 'spamfree24.org', 'spamgourmet.com', 'temp-mail.org', 'temp-mail.io', 'tempail.com',
        'tempemail.net', 'tempinbox.com', 'tempmail.com', 'tempmail.net', 'tempmailo.com', 'tempomail.fr', 'tempr.email', 'throwawaymail.com',
        'tmpmail.net', 'tmpmail.org', 'trash-mail.com', 'trashmail.com', 'trashmail.net', 'trashymail.com', 'wegwerfmail.de', 'yopmail.com',
        'yopmail.fr', 'yopmail.net', 'zoemail.net',
    ];
}

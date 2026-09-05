<?php
/**
 * Sign-up protection: email validation + reCAPTCHA, configured from the
 * super-admin settings page and enforced server-side on /register/submit.
 */
use AIWorkforce\AdminPortal;
use AIWorkforce\ApiProviders;
use AIWorkforce\SignupProtection;

function fx_sp(array $over = []): SignupProtection
{
    return SignupProtection::fromSettings($over);
}

test('signup: email validator accepts real addresses and rejects malformed ones', function () {
    SignupProtection::$dnsResolver = fn(string $d) => true;
    $p = fx_sp(['email_validation_mode' => 'syntax']);
    foreach (['Name@Example.com', 'first.last+tag@sub.example.co.uk', "o'brien@example.org", 'x@example.io'] as $ok) {
        $r = $p->validateEmail($ok);
        assert_true($r['ok'], "accepts {$ok}: {$r['reason']}");
        assert_equals(strtolower($ok), $r['email'], 'normalised to lower case');
    }
    foreach (['', 'plain', 'no@tld', 'two@@example.com', '.dot@example.com', 'dot.@example.com', 'a..b@example.com', 'a@-bad.com', 'a@exa mple.com', 'a@example..com', 'a@localhost', str_repeat('a', 65) . '@example.com'] as $bad) {
        $r = $p->validateEmail($bad);
        assert_false($r['ok'], "rejects '{$bad}'");
        assert_equals('SYNTAX', $r['reason']);
    }
    SignupProtection::$dnsResolver = null;
});

test('signup: disposable inboxes, blocked domains and allow-lists are enforced (subdomains included)', function () {
    SignupProtection::$dnsResolver = fn(string $d) => true;
    $p = fx_sp(['email_validation_mode' => 'syntax', 'email_block_disposable' => '1', 'email_blocked_domains' => "Spammer.example\ncompetitor.test", 'email_allowed_domains' => '']);
    assert_equals('DISPOSABLE', $p->validateEmail('bot@mailinator.com')['reason']);
    assert_equals('DISPOSABLE', $p->validateEmail('bot@inbox.yopmail.com')['reason'], 'subdomain of a disposable provider');
    assert_equals('DOMAIN_BLOCKED', $p->validateEmail('x@spammer.example')['reason']);
    assert_equals('DOMAIN_BLOCKED', $p->validateEmail('x@mail.competitor.test')['reason']);
    assert_true($p->validateEmail('x@gmail.com')['ok']);
    // switch the built-in list off
    $lenient = fx_sp(['email_validation_mode' => 'syntax', 'email_block_disposable' => '0']);
    assert_true($lenient->validateEmail('bot@mailinator.com')['ok']);
    // allow-list wins over everything else
    $strict = fx_sp(['email_validation_mode' => 'syntax', 'email_allowed_domains' => 'windels.example']);
    assert_equals('DOMAIN_NOT_ALLOWED', $strict->validateEmail('x@gmail.com')['reason']);
    assert_true($strict->validateEmail('x@hq.windels.example')['ok']);
    SignupProtection::$dnsResolver = null;
});

test('signup: MX mode rejects domains that do not accept mail; syntax mode never touches DNS', function () {
    $asked = [];
    SignupProtection::$dnsResolver = function (string $d) use (&$asked) { $asked[] = $d; return $d === 'example.com'; };
    $mx = fx_sp(['email_validation_mode' => 'mx']);
    assert_true($mx->validateEmail('a@example.com')['ok']);
    $r = $mx->validateEmail('a@no-such-domain-zzz.com');
    assert_equals('NO_MX', $r['reason']);
    assert_contains('does not accept mail', $r['message']);
    assert_equals(['example.com', 'no-such-domain-zzz.com'], $asked);
    $asked = [];
    assert_true(fx_sp(['email_validation_mode' => 'syntax'])->validateEmail('a@no-such-domain-zzz.com')['ok']);
    assert_equals([], $asked, 'syntax mode performs no lookup');
    SignupProtection::$dnsResolver = null;
});

test('signup: domain lists parse leniently and reject garbage', function () {
    assert_equals(['a.com', 'b.org', 'c.co.uk'], SignupProtection::parseDomainList("A.com, b.org;\n @c.co.uk.\n\nnot a domain\nlocalhost\nA.COM"));
});

test('signup: captcha off → always passes; on without keys → misconfigured and fails closed', function () {
    $off = fx_sp([]);
    assert_false($off->captchaEnabled());
    assert_true($off->verifyCaptcha(null)['ok']);
    $broken = fx_sp(['captcha_provider' => 'recaptcha_v2']);
    assert_true($broken->captchaMisconfigured());
    $r = $broken->verifyCaptcha('anything');
    assert_false($r['ok']);
    assert_equals('MISCONFIGURED', $r['reason']);
    assert_equals('MISCONFIGURED', $broken->status()['captchaState']);
    assert_true($broken->status()['signupBlocked']);
    assert_false(isset($broken->widget()['secret']), 'widget payload carries no secret');
});

test('signup: reCAPTCHA v2 siteverify — token, secret, remote IP posted; each outcome classified', function () {
    $sealed = ApiProviders::seal('secret-key-value-123');
    $p = fx_sp(['captcha_provider' => 'recaptcha_v2', 'captcha_site_key' => 'site-key-1234567890', 'captcha_secret' => $sealed]);
    assert_true($p->secretConfigured());
    assert_false($p->captchaMisconfigured());
    assert_equals('ACTIVE', $p->status()['captchaState']);
    assert_equals('MISSING_TOKEN', $p->verifyCaptcha('')['reason']);

    $seen = null;
    $reply = ['status' => 200, 'body' => '{"success": true, "hostname": "windels.example"}'];
    ApiProviders::$http = function (string $url, array $headers, ?string $body) use (&$seen, &$reply) { $seen = compact('url', 'headers', 'body'); return $reply; };
    try {
        $ok = $p->verifyCaptcha('tok-abc', '203.0.113.9');
        assert_true($ok['ok']);
        assert_equals('windels.example', $ok['hostname']);
        assert_equals(SignupProtection::SITEVERIFY_URL, $seen['url']);
        parse_str((string) $seen['body'], $posted);
        assert_equals(['secret' => 'secret-key-value-123', 'response' => 'tok-abc', 'remoteip' => '203.0.113.9'], $posted, 'secret is unsealed only for the POST');

        $reply = ['status' => 200, 'body' => '{"success": false, "error-codes": ["timeout-or-duplicate"]}'];
        assert_equals('EXPIRED', $p->verifyCaptcha('tok')['reason']);
        $reply = ['status' => 200, 'body' => '{"success": false, "error-codes": ["invalid-input-secret"]}'];
        $bad = $p->verifyCaptcha('tok');
        assert_equals('BAD_SECRET', $bad['reason']);
        assert_contains('temporarily unavailable', $bad['message']);
        $reply = ['status' => 200, 'body' => '{"success": false, "error-codes": ["invalid-input-response"]}'];
        assert_equals('FAILED', $p->verifyCaptcha('tok')['reason']);
        $reply = ['status' => 0, 'body' => ''];
        assert_equals('UNREACHABLE', $p->verifyCaptcha('tok')['reason'], 'no network → fail closed');
        $reply = ['status' => 200, 'body' => 'not json'];
        assert_equals('UNREACHABLE', $p->verifyCaptcha('tok')['reason']);
        $reply = ['status' => 500, 'body' => '{"success": true}'];
        assert_false($p->verifyCaptcha('tok')['ok'], 'a 5xx is never a pass');
    } finally {
        ApiProviders::$http = null;
    }
});

test('signup: reCAPTCHA v3 enforces action and the configured minimum score', function () {
    $p = fx_sp(['captcha_provider' => 'recaptcha_v3', 'captcha_site_key' => 'site-key-1234567890', 'captcha_secret' => ApiProviders::seal('secret-key-value-123'), 'captcha_min_score' => '0.7']);
    assert_equals(0.7, $p->minScore);
    $reply = null;
    ApiProviders::$http = function () use (&$reply) { return $reply; };
    try {
        $reply = ['status' => 200, 'body' => '{"success": true, "score": 0.9, "action": "register"}'];
        assert_true($p->verifyCaptcha('t')['ok']);
        $reply = ['status' => 200, 'body' => '{"success": true, "score": 0.3, "action": "register"}'];
        $low = $p->verifyCaptcha('t');
        assert_equals('LOW_SCORE', $low['reason']);
        assert_equals(0.3, $low['score']);
        $reply = ['status' => 200, 'body' => '{"success": true, "score": 0.9, "action": "login"}'];
        assert_equals('WRONG_ACTION', $p->verifyCaptcha('t')['reason'], 'a token minted for another form is refused');
    } finally {
        ApiProviders::$http = null;
    }
    // score clamps
    assert_equals(0.1, fx_sp(['captcha_min_score' => '-3'])->minScore);
    assert_equals(0.9, fx_sp(['captcha_min_score' => '7'])->minScore);
    assert_equals(0.5, fx_sp(['captcha_min_score' => 'abc'])->minScore);
});

test('signup: environment keys override stored keys', function () {
    putenv('VP_RECAPTCHA_SITE_KEY=env-site-key-12345');
    putenv('VP_RECAPTCHA_SECRET=env-secret-key-12345');
    try {
        $p = fx_sp(['captcha_provider' => 'recaptcha_v2', 'captcha_site_key' => 'stored-site-key-000', 'captcha_secret' => ApiProviders::seal('stored-secret-000')]);
        assert_equals('env-site-key-12345', $p->siteKey);
        assert_true($p->fromEnv);
        $seen = null;
        ApiProviders::$http = function (string $u, array $h, ?string $b) use (&$seen) { $seen = $b; return ['status' => 200, 'body' => '{"success":true}']; };
        $p->verifyCaptcha('t');
        ApiProviders::$http = null;
        parse_str((string) $seen, $posted);
        assert_equals('env-secret-key-12345', $posted['secret']);
    } finally {
        putenv('VP_RECAPTCHA_SITE_KEY');
        putenv('VP_RECAPTCHA_SECRET');
        ApiProviders::$http = null;
    }
});

test('signup: settings normalisation seals the secret, keeps it on blank, clears on request, and warns', function () {
    $n = SignupProtection::normalizeSettings([
        'email_validation_mode' => 'bogus', 'email_block_disposable' => '1',
        'email_blocked_domains' => "Foo.com\nnot valid", 'email_allowed_domains' => '',
        'captcha_provider' => 'recaptcha_v2', 'captcha_site_key' => 'site-key-1234567890', 'captcha_secret' => 'secret-key-value-123', 'captcha_min_score' => '2',
    ], '');
    $v = $n['values'];
    assert_equals('mx', $v['email_validation_mode'], 'unknown mode falls back to the safe default');
    assert_equals('foo.com', $v['email_blocked_domains']);
    assert_equals('0.9', $v['captcha_min_score']);
    assert_not_equals('secret-key-value-123', $v['captcha_secret'], 'never stored in plaintext');
    assert_equals('secret-key-value-123', ApiProviders::open($v['captcha_secret']));
    assert_equals([], $n['warnings']);

    $keep = SignupProtection::normalizeSettings(['captcha_provider' => 'recaptcha_v2', 'captcha_site_key' => 'site-key-1234567890', 'captcha_secret' => ''], $v['captcha_secret']);
    assert_equals($v['captcha_secret'], $keep['values']['captcha_secret'], 'blank keeps the stored secret');

    $clear = SignupProtection::normalizeSettings(['captcha_provider' => 'recaptcha_v2', 'captcha_site_key' => 'site-key-1234567890'], $v['captcha_secret'], true);
    assert_equals('', $clear['values']['captcha_secret']);
    assert_true(count($clear['warnings']) > 0, 'warns that sign-up is now blocked');
    assert_contains('no secret key', implode(' ', $clear['warnings']));

    $badKey = SignupProtection::normalizeSettings(['captcha_provider' => 'off', 'captcha_site_key' => '<script>'], '');
    assert_equals('', $badKey['values']['captcha_site_key']);
    assert_contains('site key was ignored', implode(' ', $badKey['warnings']));
});

test('signup: settings round-trip through the admin portal and never expose the secret', function () {
    $portal = new AdminPortal(platform()->model);
    $portal->ensureSchema();
    assert_true(isset(AdminPortal::SETTING_DEFAULTS['signup']['captcha_provider']));
    $n = SignupProtection::normalizeSettings(['captcha_provider' => 'recaptcha_v3', 'captcha_site_key' => 'site-key-1234567890', 'captcha_secret' => 'portal-secret-9876543210', 'captcha_min_score' => '0.6', 'email_block_disposable' => '1', 'email_validation_mode' => 'syntax'], '');
    $portal->saveSettings($n['values'], 'signup', 1, 2000);
    $p = SignupProtection::fromPortal($portal);
    assert_equals('recaptcha_v3', $p->captchaProvider);
    assert_equals(0.6, $p->minScore);
    assert_true($p->secretConfigured());
    assert_equals('syntax', $p->emailMode);
    $blob = json_encode($portal->settingsByCategory());
    assert_false(str_contains($blob, 'portal-secret-9876543210'), 'plaintext secret is not in the settings dump');
    assert_false(str_contains(json_encode($p->status()), 'portal-secret'), 'status() carries no secret');
    assert_false(str_contains(json_encode($p->widget()), 'portal-secret'), 'widget() carries no secret');
    // reset so later cases start from defaults
    $portal->saveSettings(array_merge(SignupProtection::DEFAULTS, ['captcha_secret' => '']), 'signup', 1, 2000);
});

test('signup: register page renders the widget for the configured provider — and never the secret', function () {
    $ci = ci();
    $render = function (array $captcha) use ($ci): string {
        ob_start();
        $ci->load->view('auth/register', ['title' => 'Create an account', 'error' => null, 'notice' => null, 'csrfToken' => 'tok', 'securityQuestions' => \AIWorkforce\IdentitySchema::SECURITY_QUESTIONS, 'captcha' => $captcha, 'old' => ['email' => 'kept@example.com']]);
        return (string) ob_get_clean();
    };
    $off = $render(fx_sp([])->widget());
    assert_not_contains('recaptcha/api.js', $off);
    assert_contains('name="website_url"', $off, 'honeypot is always present');
    assert_contains('value="kept@example.com"', $off, 'fields survive a validation redirect');
    assert_contains('Temporary or disposable email addresses', $off, 'live email validation script present');

    $v2 = $render(fx_sp(['captcha_provider' => 'recaptcha_v2', 'captcha_site_key' => 'site-key-1234567890', 'captcha_secret' => ApiProviders::seal('secret-key-value-123')])->widget());
    assert_contains('https://www.google.com/recaptcha/api.js', $v2);
    assert_contains('class="g-recaptcha" data-sitekey="site-key-1234567890"', $v2);
    assert_not_contains('secret-key-value-123', $v2);

    $v3 = $render(fx_sp(['captcha_provider' => 'recaptcha_v3', 'captcha_site_key' => 'site-key-1234567890', 'captcha_secret' => ApiProviders::seal('secret-key-value-123')])->widget());
    assert_contains('recaptcha/api.js?render=site-key-1234567890', $v3);
    assert_contains('name="g-recaptcha-response" id="reg-captcha-token"', $v3);
    assert_contains('grecaptcha.execute', $v3);
    assert_not_contains('secret-key-value-123', $v3);

    $broken = $render(fx_sp(['captcha_provider' => 'recaptcha_v2'])->widget());
    assert_contains('Registration is temporarily unavailable', $broken);
    assert_contains('id="register-submit" disabled', $broken);
});

test('signup: controller enforces honeypot, captcha and email validation server-side; settings UI is wired', function () {
    $auth = file_get_contents(FCPATH . 'application/controllers/Auth.php');
    assert_contains('SignupProtection::honeypotTripped', $auth);
    assert_contains('->verifyCaptcha(', $auth);
    assert_contains('->validateEmail(', $auth);
    assert_contains("emit('SIGNUP_BLOCKED'", $auth);
    // the captcha is checked before the cheap field checks, so bots cannot probe rules captcha-free
    assert_true(strpos($auth, '->verifyCaptcha(') < strpos($auth, '$this->validUsername($username)'), 'captcha precedes field validation');
    $admin = file_get_contents(FCPATH . 'application/controllers/Admin.php');
    assert_contains("SignupProtection::normalizeSettings", $admin);
    assert_contains("unset(\$data['settings']['signup']['captcha_secret'])", $admin, 'sealed secret is not handed to the view');
    $settings = file_get_contents(FCPATH . 'application/views/admin/settings.php');
    assert_contains('id="signup"', $settings);
    assert_contains('name="category" value="signup"', $settings);
    assert_contains('name="captcha_provider"', $settings);
    assert_contains('type="password" name="captcha_secret"', $settings);
    assert_contains('name="captcha_secret_clear"', $settings);
    assert_contains('name="email_validation_mode"', $settings);
    assert_contains('name="email_block_disposable"', $settings);
    $dash = file_get_contents(FCPATH . 'application/views/admin/index.php');
    assert_contains('id="signup-protection"', $dash);
    assert_contains('/admin/settings#signup', $dash);
});

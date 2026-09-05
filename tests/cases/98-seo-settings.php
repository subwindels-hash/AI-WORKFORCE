<?php
/**
 * Tests for super-admin SEO settings: DB overrides merge over config/env
 * defaults, the System Settings SEO section, and the public runtime wiring
 * (meta tags, robots.txt, sitemap.xml).
 */
use AIWorkforce\AdminPortal;
use AIWorkforce\SeoSettings;

class Fx98FakeDb
{
    public function __construct(private array $rows, private bool $explode = false) {}
    public function get_where(string $table, array $where)
    {
        if ($this->explode) throw new \RuntimeException('db down');
        $rows = $this->rows;
        return new class($rows) {
            public function __construct(private array $rows) {}
            public function result_array(): array { return $this->rows; }
        };
    }
}

function fx98_config(): array
{
    return [
        'site_name' => 'Env Site', 'title_suffix' => ' · Env', 'description' => 'Env description',
        'keywords' => 'env,keywords', 'robots' => 'index,follow', 'canonical' => 'https://env.example/',
        'og_image' => 'https://env.example/og.png', 'theme_color' => '#000000',
    ];
}

test('seo overrides win over config, blanks fall back, unknown keys ignored', function () {
    $db = new Fx98FakeDb([
        ['k' => 'seo_description', 'v' => 'Admin description'],
        ['k' => 'seo_robots', 'v' => 'noindex,nofollow'],
        ['k' => 'seo_keywords', 'v' => ''],
        ['k' => 'seo_bogus', 'v' => 'x'],
    ]);
    $out = SeoSettings::effective(fx98_config(), $db);
    assert_equals('Admin description', $out['description']);
    assert_equals('noindex,nofollow', $out['robots']);
    assert_equals('env,keywords', $out['keywords'], 'blank override keeps the server default');
    assert_equals('Env Site', $out['site_name']);
    assert_false(isset($out['seo_bogus']), 'unknown keys never leak into settings');
});

test('seo effective never throws: null db and db errors return config', function () {
    $config = fx98_config();
    assert_equals($config, SeoSettings::effective($config, null));
    assert_equals($config, SeoSettings::effective($config, new Fx98FakeDb([], true)));
});

test('seo settings category matches the override keys exactly', function () {
    $defaults = AdminPortal::SETTING_DEFAULTS['seo'] ?? null;
    assert_true(is_array($defaults), 'seo category registered');
    $expected = array_values(SeoSettings::KEYS);
    sort($expected);
    $actual = array_keys($defaults);
    sort($actual);
    assert_equals($expected, $actual, 'every override key is editable, nothing extra');
    foreach ($defaults as $v) assert_equals('', $v, 'defaults are blank = use server default');
    $admin = file_get_contents(FCPATH . 'application/controllers/Admin.php');
    assert_contains('SETTING_DEFAULTS[$category]', $admin, 'settings_save handles the seo category generically');
});

test('seo settings section renders all fields on System Settings', function () {
    if (!function_exists('e')) {
        function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    }
    ob_start();
    ci()->load->view('admin/settings', [
        'settings' => ['seo' => ['seo_robots' => 'noindex,nofollow', 'seo_description' => 'Saved desc']],
        'smtp' => [], 'smtpOk' => null, 'smtpError' => null,
        'session' => ['expiration' => 7200, 'httponly' => true, 'samesite' => 'Lax', 'secure' => false],
        'csrfToken' => 'tok',
    ]);
    $html = (string) ob_get_clean();
    assert_contains('id="seo"', $html);
    assert_contains('name="category" value="seo"', $html);
    foreach (array_values(SeoSettings::KEYS) as $field) assert_contains('name="' . $field . '"', $html);
    assert_contains('value="noindex,nofollow" selected', $html, 'saved robots choice is preselected');
    assert_contains('Saved desc', $html);
});

test('public header and seo documents use the effective settings', function () {
    $header = file_get_contents(FCPATH . 'application/views/site/layout/header.php');
    assert_contains('SeoSettings::effective', $header);
    assert_contains('name="keywords"', $header);
    assert_contains('rel="canonical"', $header);
    assert_contains('name="theme-color"', $header);
    $seo = file_get_contents(FCPATH . 'application/controllers/Seo.php');
    assert_contains('class Seo extends MY_Controller', $seo);
    assert_contains('SeoSettings::effective', $seo);
    ob_start();
    ci()->load->view('site/layout/header', ['title' => 'Home', 'active' => 'home', 'user' => null]);
    $html = (string) ob_get_clean();
    assert_contains('<title>', $html);
    assert_contains('name="description"', $html);
    assert_contains('name="robots"', $html);
    assert_contains('og:site_name', $html);
});

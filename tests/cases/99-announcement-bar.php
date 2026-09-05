<?php
/**
 * Tests for the super-admin announcement bar settings: override priority
 * (dashboard > env > defaults), the show/hide toggle, message splitting,
 * the settings form, and end-to-end rendering through the real database.
 */
use AIWorkforce\AdminPortal;
use AIWorkforce\AnnouncementBar;

class Fx99FakeDb
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

test('announcement splitting accepts newlines and the legacy pipe', function () {
    assert_equals(['a', 'b', 'c'], AnnouncementBar::split("a\nb|c"));
    assert_equals(['a', 'b'], AnnouncementBar::split("  a  \r\n\r\nb  "));
    assert_equals([], AnnouncementBar::split("  \n | "));
    assert_true(count(AnnouncementBar::defaults()) >= 1, 'built-in defaults exist');
});

test('announcement content prioritizes the dashboard override', function () {
    $db = new Fx99FakeDb([
        ['k' => 'announcement_enabled', 'v' => '1'],
        ['k' => 'announcement_messages', 'v' => "Hello\nWorld|Again"],
    ]);
    $out = AnnouncementBar::content($db);
    assert_true($out['enabled']);
    assert_equals(['Hello', 'World', 'Again'], $out['messages']);
});

test('announcement toggle off and empty override both silence the bar', function () {
    $off = AnnouncementBar::content(new Fx99FakeDb([['k' => 'announcement_enabled', 'v' => '0']]));
    assert_false($off['enabled']);
    $empty = AnnouncementBar::content(new Fx99FakeDb([['k' => 'announcement_messages', 'v' => '']]));
    assert_true($empty['enabled']);
    assert_equals([], $empty['messages'], 'saved-but-empty override shows nothing');
});

test('announcement content never throws and falls back without overrides', function () {
    $a = AnnouncementBar::content(null);
    assert_true($a['enabled']);
    assert_true(count($a['messages']) >= 1, 'env or defaults provide messages');
    $b = AnnouncementBar::content(new Fx99FakeDb([], true));
    assert_true($b['enabled']);
    assert_true(count($b['messages']) >= 1);
});

test('announcement settings category and save handling are wired', function () {
    $defaults = AdminPortal::SETTING_DEFAULTS['announcement'] ?? null;
    assert_true(is_array($defaults), 'announcement category registered');
    assert_equals('', $defaults['announcement_messages'] ?? null);
    assert_equals('1', $defaults['announcement_enabled'] ?? null);
    $admin = file_get_contents(FCPATH . 'application/controllers/Admin.php');
    assert_contains('announcement', $admin);
    assert_contains("=== 'announcement'", $admin);
    assert_contains('announcement_enabled', $admin);
    $partial = file_get_contents(FCPATH . 'application/views/partials/announcement_bar.php');
    assert_contains('AnnouncementBar::content', $partial);
});

test('announcement settings section renders on System Settings', function () {
    if (!function_exists('e')) {
        function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    }
    ob_start();
    ci()->load->view('admin/settings', [
        'settings' => ['announcement' => ['announcement_enabled' => '0', 'announcement_messages' => "Line one\nLine two"]],
        'smtp' => [], 'smtpOk' => null, 'smtpError' => null,
        'session' => ['expiration' => 7200, 'httponly' => true, 'samesite' => 'Lax', 'secure' => false],
        'csrfToken' => 'tok',
    ]);
    $html = (string) ob_get_clean();
    assert_contains('id="announcement"', $html);
    assert_contains('name="category" value="announcement"', $html);
    assert_contains('name="announcement_enabled"', $html);
    assert_contains('name="announcement_messages"', $html);
    assert_contains('Line one', $html);
});

test('announcement bar renders end to end through the real database', function () {
    $model = ci()->AIWorkforce_model;
    (new AdminPortal($model))->ensureSchema();
    $db = $model->db;
    $put = function (string $k, string $v) use ($db) {
        $row = ['k' => $k, 'v' => $v, 'category' => 'announcement', 'updated_at' => gmdate('c'), 'updated_by' => null];
        if ($db->get_where('platform_settings', ['k' => $k], 1)->row_array()) $db->where('k', $k)->update('platform_settings', $row);
        else $db->insert('platform_settings', $row);
    };
    $render = function (): string {
        ob_start();
        ci()->load->view('partials/announcement_bar');
        return (string) ob_get_clean();
    };
    try {
        $put('announcement_enabled', '1');
        $put('announcement_messages', "E2E hello\nE2E world");
        $html = $render();
        assert_contains('E2E hello', $html);
        assert_contains('E2E world', $html);
        $put('announcement_enabled', '0');
        assert_equals('', $render(), 'toggle off renders nothing');
    } finally {
        $db->where('k', 'announcement_enabled')->delete('platform_settings');
        $db->where('k', 'announcement_messages')->delete('platform_settings');
    }
    assert_false(str_contains($render(), 'E2E hello'), 'override removed after cleanup');
});

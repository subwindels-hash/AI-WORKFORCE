<?php
/**
 * Hardening: a PHP fatal (e.g. a parse error in a file loaded on every
 * request, which took the whole domain down) must never present visitors with
 * an empty 500. The root .htaccess maps server errors to a static page, and
 * that page ships in the cPanel deployment archive.
 */

test('.htaccess maps server errors to a static page, never a PHP target', function () {
    $ht = file_get_contents(FCPATH . '.htaccess');
    assert_contains('ErrorDocument 500 /error500.html', $ht, '500 mapped');
    assert_contains('ErrorDocument 503 /error500.html', $ht, '503 mapped');
    // The target must be a static file, not a PHP script (a fatal inside a
    // PHP error document would recurse).
    foreach (array_filter(array_map('trim', explode("\n", $ht)), fn($l) => str_starts_with($l, 'ErrorDocument')) as $line) {
        $target = preg_split('/\s+/', $line)[2] ?? '';
        assert_false(str_ends_with($target, '.php'), 'ErrorDocument target is static: ' . $line);
    }
    // The rewrite front controller still routes to index.php and blocks data dirs.
    assert_contains('index.php/$1', $ht, 'front controller rewrite');
    assert_contains('database|tests|runtime|python-services', $ht, 'data dirs blocked');
});

test('the static error page exists and is self-contained', function () {
    $page = file_get_contents(FCPATH . 'error500.html');
    assert_contains('<title>', $page, 'has title');
    assert_contains('temporarily unavailable', $page, 'friendly copy');
    assert_false(str_contains($page, '<?php'), 'no PHP inside the static error page');
});

test('the error page ships in the cPanel deployment archive', function () {
    $zip = new ZipArchive();
    assert_equals(true, $zip->open(FCPATH . 'application-deployment.zip'), 'open archive');
    $bundled = $zip->getFromName('error500.html');
    assert_true(is_string($bundled), 'archive contains error500.html');
    assert_equals(file_get_contents(FCPATH . 'error500.html'), $bundled, 'archive error500.html matches source');
    $ht = $zip->getFromName('.htaccess');
    assert_true(is_string($ht) && str_contains($ht, 'ErrorDocument 500'), 'archive .htaccess contains ErrorDocument');
    $zip->close();
    return ['msg' => 'hardening present in source and archive'];
});

<?php
/** Authentication and administrator page wiring review for browser deployments. */
test('user and administrator login/account pages are routed and use the secure controller', function () {
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    assert_contains("\$route['login'] = 'auth/index';", $routes);
    assert_contains("\$route['admin/login'] = 'auth/admin_login';", $routes);
    assert_contains("\$route['account'] = 'auth/account';", $routes);
    assert_contains("\$route['admin'] = 'admin/index';", $routes);
    assert_true(is_file(FCPATH . 'application/controllers/Auth.php'));
    assert_true(is_file(FCPATH . 'application/controllers/Admin.php'));
    assert_true(is_file(FCPATH . 'application/views/auth/login.php'));
    assert_true(is_file(FCPATH . 'application/views/auth/account.php'));
    assert_true(is_file(FCPATH . 'application/views/admin/index.php'));
    assert_contains('csrf_token', file_get_contents(FCPATH . 'application/views/auth/account.php'));
    assert_contains('system.super_admin', file_get_contents(FCPATH . 'application/controllers/Admin.php'));
});

test('generated brand assets are wired into PHP views', function () {
    assert_true(is_file(FCPATH . 'assets/images/aegis-mark.png'));
    assert_true(is_file(FCPATH . 'assets/images/ai-agent-avatar.png'));
    assert_true(is_file(FCPATH . 'assets/images/customer-review.png'));
    assert_contains('/assets/images/aegis-mark.png', file_get_contents(FCPATH . 'application/views/layout/header.php'));
    assert_contains('/assets/images/ai-agent-avatar.png', file_get_contents(FCPATH . 'application/views/admin/index.php'));
});

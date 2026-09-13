<?php
/** Public site, authentication gates and dashboard separation. */

test('public website routes and views exist without exposing dashboards', function () {
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    assert_contains("\$route['default_controller'] = 'site';", $routes);
    assert_contains("\$route['dashboard'] = 'workspace/index';", $routes);
    assert_contains("\$route['analysis'] = 'welcome';", $routes);
    assert_contains("\$route['register'] = 'auth/register';", $routes);
    assert_contains("\$route['access-denied'] = 'auth/denied';", $routes);
    assert_contains("\$route['admin/dashboard'] = 'admin/index';", $routes);
    foreach (['about', 'services', 'how-it-works', 'locations', 'safety', 'faq', 'contact'] as $path) {
        assert_contains("\$route['{$path}']", $routes);
    }
    assert_true(is_file(FCPATH . 'application/controllers/Site.php'));
    assert_true(is_file(FCPATH . 'application/controllers/Workspace.php'));
    assert_true(is_file(FCPATH . 'application/views/site/home.php'));
    assert_true(is_file(FCPATH . 'application/views/workspace/index.php'));
    $home = file_get_contents(FCPATH . 'application/views/site/home.php');
    assert_false(str_contains($home, 'href="/analysis"'));
    assert_contains('Get started', $home);
});

test('workspace controllers require App_Controller login gate', function () {
    // Trading, workforce and account surfaces stay hard-gated: a logged-out
    // visitor is redirected to /login by App_Controller.
    foreach (['Welcome', 'Workspace', 'Paper', 'Admin', 'Lang_learn', 'Leads'] as $name) {
        $src = file_get_contents(FCPATH . 'application/controllers/' . $name . '.php');
        assert_contains('extends App_Controller', $src, $name . ' must extend App_Controller');
    }
    $core = file_get_contents(FCPATH . 'application/core/MY_Controller.php');
    assert_contains('function requireLogin', $core);
    assert_contains('function requireAdminPage', $core);
    $api = $core;
    assert_contains('unauthenticated', $api);
    $admin = file_get_contents(FCPATH . 'application/controllers/Admin.php');
    assert_contains('requireAdminPage', $admin);
});

test('sports and football read pages render a shell + in-page sign-in gate instead of redirecting', function () {
    // Deliberate exception to the hard login gate: the read-only Sports and
    // Football consoles must render their correct page shell for a logged-out
    // visitor and show an in-page sign-in prompt, rather than bouncing to
    // /login. Data stays gated (optionalLogin returns null → the gate renders),
    // and mutations still enforce the RBAC matrix.
    foreach (['Sports', 'Football'] as $name) {
        $src = file_get_contents(FCPATH . 'application/controllers/' . $name . '.php');
        assert_contains('extends MY_Controller', $src, $name . ' must extend MY_Controller (soft gate, not a hard login redirect)');
        assert_false(str_contains($src, 'extends App_Controller'), $name . ' must not hard-gate via App_Controller');
        assert_contains('optionalLogin', $src, $name . ' must resolve identity without redirecting logged-out visitors');
        assert_contains('signin_gate', $src, $name . ' must render the in-page sign-in gate for guests');
    }
    $core = file_get_contents(FCPATH . 'application/core/MY_Controller.php');
    assert_contains('function optionalLogin', $core);
    assert_contains('function signInUrl', $core);
    assert_true(is_file(FCPATH . 'application/views/partials/signin_gate.php'), 'the shared sign-in gate partial must exist');
});

test('login preserves the requested route (return_to) for everyone, admins included', function () {
    $auth = file_get_contents(FCPATH . 'application/controllers/Auth.php');
    // The safe same-origin return_to is honoured before the admin→/admin
    // default, so a visitor who asked for /sports lands on /sports after signing
    // in rather than being diverted to /admin.
    assert_contains('$safeNext', $auth);
    $pos_next = strpos($auth, 'if ($safeNext !== \'\') { redirect($safeNext); return; }');
    $pos_admin = strpos($auth, "if (\$admin || \$this->isAdmin(\$user)) { redirect('/admin'); return; }");
    assert_true($pos_next !== false, 'login must redirect to a safe return_to');
    assert_true($pos_admin !== false && $pos_next < $pos_admin, 'return_to must be honoured before the admin default');
});

test('member registration role is seeded in the RBAC matrix', function () {
    assert_true(isset(AI_WORKFORCE_RBAC_ROLES['platform_member']));
    assert_in_array('trading.view', AI_WORKFORCE_RBAC_GRANTS['platform_member']);
    assert_in_array('sports.view', AI_WORKFORCE_RBAC_GRANTS['platform_member']);
    assert_false(in_array('system.super_admin', AI_WORKFORCE_RBAC_GRANTS['platform_member'], true));
});

test('sitemap lists public pages and robots hide dashboards', function () {
    $seo = file_get_contents(FCPATH . 'application/controllers/Seo.php');
    assert_contains("'/about'", $seo);
    assert_contains("'/register'", $seo);
    assert_false(str_contains($seo, "'/strategy'"));
    assert_contains('Disallow: /dashboard', $seo);
});

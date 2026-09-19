<?php
/**
 * Full-project UI audit (routes · buttons · auth · dashboard chrome).
 *
 * Static review of the parts that a browser click-test verifies dynamically:
 * every sidebar item, profile-menu action, footer link and homepage CTA must
 * point at a routed destination, the dashboard chrome must stay compact and
 * consistent, and role gates must be enforced in the controller layer.
 */

test('dashboard sidebar contains every required item with a real route and an icon', function () {
    $header = file_get_contents(FCPATH . 'application/views/layout/header.php');
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    $required = [
        'Dashboard'       => '/dashboard',
        'AI Workforce'    => '/analysis',
        'AI Teacher'      => '/app/languages/teacher',
        'My Languages'    => '/app/languages',
        'Lead Discovery'  => '/leads',
        'Pipeline'        => '/lead-pipeline',
        'Paper Trading'   => '/paper',
        'Strategy Lab'    => '/strategy',
        'Analytics'       => '/journal',
        'Execution'       => '/execution',
        'Brokers'         => '/brokers',
        'Risk Center'     => '/risk',
        'Sports Intel'    => '/sports',
        'Alerts'          => '/notifications',
        'Settings'        => '/account',
        'Help'            => '/faq',
    ];
    foreach ($required as $label => $href) {
        assert_contains('href="' . $href . '"', $header, "sidebar item '$label' must link to $href");
    }
    // Every sidebar link uses the SPA navigation hook (keeps the shell mounted).
    assert_contains('data-dashboard-link', $header);
    // Every required destination must exist as a route (or resolve through
    // CodeIgniter's default controller/method routing).
    foreach ($required as $label => $href) {
        $segments = trim($href, '/');
        $hasRoute = str_contains($routes, "\$route['" . $segments . "']")
            || ($segments !== '' && preg_match("/\\\$route\['" . preg_quote($segments, '/') . "\//", $routes));
        if (!$hasRoute) {
            $first = explode('/', $segments)[0];
            $controller = FCPATH . 'application/controllers/' . str_replace(' ', '', ucwords(str_replace('_', ' ', $first))) . '.php';
            $hasRoute = is_file($controller);
        }
        assert_true($hasRoute, "route for sidebar destination '$href' must exist");
    }
    // No sidebar item may be a dead "#" link.
    assert_false(str_contains($header, 'href="#"'), 'sidebar must not contain dead href="#" links');
});

test('sidebar icons are one consistent compact size', function () {
    $css = file_get_contents(FCPATH . 'assets/css/ai_workforce.css');
    assert_contains('.sidebar a svg { width: 20px; height: 20px;', $css, 'sidebar svg icons sized 20x20');
    $header = file_get_contents(FCPATH . 'application/views/layout/header.php');
    // Sidebar icons rely on the stylesheet size: no inline width/height overrides.
    preg_match_all('#<a href="/[^"]*" class="[^"]*" data-dashboard-link><svg[^>]*>#', $header, $m);
    foreach ($m[0] as $anchor) {
        assert_false(preg_match('/\swidth=/', $anchor), 'sidebar svg must not carry inline width: ' . $anchor);
        assert_false(preg_match('/\sheight=/', $anchor), 'sidebar svg must not carry inline height: ' . $anchor);
    }
    // Section labels, brand and logout keep the sidebar tidy.
    assert_contains('.sidebar-label', $css);
    assert_contains('.sidebar-logout', $css);
});

test('top-right controls are compact and contain no oversized dot glyph', function () {
    $header = file_get_contents(FCPATH . 'application/views/layout/header.php');
    $css = file_get_contents(FCPATH . 'assets/css/ai_workforce.css');
    // Status pill uses a small CSS dot, not a large text bullet.
    assert_contains('statuspill', $header);
    assert_false(str_contains($header, "● Kill switch"), 'statuspill must not render a text bullet glyph');
    assert_contains('.statuspill .pill-dot { width: 6px; height: 6px;', $css, 'statuspill dot is a 6px element');
    // Notification icon button and avatar stay small.
    assert_contains('.icon-btn svg { width: 20px; height: 20px; }', $css);
    assert_contains('.profile .avatar { width: 28px; height: 28px;', $css);
    // Notifications dot is tiny.
    assert_contains('.icon-btn .dot { position: absolute;', $css);
});

test('profile menu exposes working actions (settings, security, notifications, sign out)', function () {
    $header = file_get_contents(FCPATH . 'application/views/layout/header.php');
    $account = file_get_contents(FCPATH . 'application/views/auth/account.php');
    assert_contains('id="profile-menu"', $header);
    assert_contains('href="/account"', $header);
    assert_contains('href="/account#security"', $header, 'profile menu has a Security action');
    assert_contains('href="/notifications"', $header);
    assert_contains('action="/logout"', $header, 'profile menu contains the logout form');
    assert_contains('name="csrf_token"', $header, 'logout form is CSRF protected');
    // The Security target section exists on the account page.
    assert_contains('id="security"', $account, 'account page has a #security section');
});

test('logout is POST + CSRF, destroys the session and shows the goodbye page', function () {
    $auth = file_get_contents(FCPATH . 'application/controllers/Auth.php');
    assert_contains('public function logout()', $auth);
    assert_contains('sess_destroy', $auth);
    // POST-only enforcement via CSRF token check on logout.
    assert_contains('validAuthCsrf', $auth);
    // The signed-out goodbye page is rendered after the session is destroyed.
    assert_contains('load->view(\'auth/goodbye\'', $auth);
    $goodbye = file_get_contents(FCPATH . 'application/views/auth/goodbye.php');
    assert_contains('You\'ve been signed out', $goodbye);
    assert_contains('action="/login"', $goodbye);
    $header = file_get_contents(FCPATH . 'application/views/layout/header.php');
    assert_contains('method="post" action="/logout"', $header);
    assert_contains('csrf_token', $header);
});

test('homepage CTAs and site footer links all point at routed destinations', function () {
    $home = file_get_contents(FCPATH . 'application/views/site/home.php');
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    assert_false(str_contains($home, 'href="#"'), 'homepage must not contain dead href="#" links');
    preg_match_all('~href="(/[^"#]*)"~', $home, $m);
    assert_true(count($m[1]) > 8, 'homepage exposes its navigation and CTA links');
    foreach (array_unique($m[1]) as $href) {
        if (str_starts_with($href, '/assets/') || str_starts_with($href, '/api/')) continue;
        $segments = trim($href, '/');
        assert_true(
            str_contains($routes, "\$route['" . $segments . "']") || str_contains($routes, "\$route['" . $segments . "']"),
            "homepage link '$href' must have a route"
        );
    }
    $footer = file_get_contents(FCPATH . 'application/views/site/layout/footer.php');
    assert_false(str_contains($footer, 'href="#"'), 'footer must not contain dead href="#" links');
    assert_contains('href="/reviews"', $footer, 'footer exposes the public reviews page');
    preg_match_all('#href="([^"]+)"#', $footer, $fm);
    $fm[1] = array_values(array_filter($fm[1], fn ($h) => $h !== '#'));
    assert_true(count($fm[1]) > 4, 'footer must expose its links');
    foreach (array_unique($fm[1]) as $href) {
        if (str_contains($href, '<?')) continue; // deployment-configured links are validated by the view before rendering
        if (preg_match('#^(https?://|mailto:|tel:)#', $href)) continue; // real external destinations (web, email, phone dialer) are fine
        $segments = trim($href, '/');
        assert_true(
            str_contains($routes, "\$route['" . $segments . "']") || str_contains($routes, "\$route['" . $segments . "']"),
            "footer link '$href' must have a route"
        );
    }
});

test('public footer social buttons use configured official HTTPS channels', function () {
    $footer = file_get_contents(FCPATH . 'application/views/site/layout/footer.php');
    $env = file_get_contents(FCPATH . '.env.example');
    $channels = [
        'FACEBOOK' => 'Facebook',
        'INSTAGRAM' => 'Instagram',
        'X' => 'X',
        'LINKEDIN' => 'LinkedIn',
        'TELEGRAM' => 'Telegram',
        'WHATSAPP' => 'WhatsApp',
        'YOUTUBE' => 'YouTube',
    ];
    foreach ($channels as $key => $label) {
        assert_contains("VP_SOCIAL_{$key}", $footer, "footer reads the {$label} channel setting");
        assert_contains("VP_SOCIAL_{$key}=", $env, ".env.example documents the {$label} channel setting");
        assert_contains("'label' => '{$label}'", $footer, "footer includes the {$label} button");
    }
    assert_contains("\$scheme !== 'https'", $footer, 'non-HTTPS social destinations stay hidden');
    assert_contains('FILTER_VALIDATE_URL', $footer, 'malformed social destinations stay hidden');
    assert_contains('target="_blank"', $footer, 'social destinations open separately');
    assert_contains('rel="noopener noreferrer"', $footer, 'new-tab social links cannot control the opener');
    assert_contains('aria-label="Follow WINDELS on', $footer, 'social buttons carry accessible names');
});

test('footer social row renders by default from application/config/social.php', function () {
    $footer = file_get_contents(FCPATH . 'application/views/site/layout/footer.php');
    $configPath = FCPATH . 'application/config/social.php';
    assert_true(is_file($configPath), 'shipped social channel configuration exists');
    // The view must read the config file, and the env variable must still win.
    assert_contains("config->load('social', true)", $footer, 'footer loads the social config');
    assert_contains("\$socialConfigured[\$channel['key']]", $footer, 'footer falls back to the configured channel URL');
    assert_contains("\$url = trim((string) getenv(\$channel['env']));", $footer, 'VP_SOCIAL_* keeps the highest precedence');

    // Load the config the way CodeIgniter does and validate every shipped value
    // through the same rules the view applies: nothing dead or non-HTTPS ships.
    $config = [];
    require $configPath;
    $channels = (array) ($config['channels'] ?? []);
    assert_true(count($channels) >= 5, 'a default social destination ships for most channels');
    $rendered = 0;
    foreach ($channels as $key => $url) {
        $url = trim((string) $url);
        if ($url === '') continue; // deliberately unset (e.g. WhatsApp without a phone number)
        assert_true(str_starts_with($url, 'https://'), "default {$key} URL is HTTPS");
        assert_true(filter_var($url, FILTER_VALIDATE_URL) !== false, "default {$key} URL is well formed");
        $rendered++;
    }
    assert_true($rendered >= 5, 'the footer social row is visible out of the box');
});

test('footer social icons are official brand marks at one small size', function () {
    $footer = file_get_contents(FCPATH . 'application/views/site/layout/footer.php');
    $css = file_get_contents(FCPATH . 'assets/css/public.css');
    // Single stylesheet rule owns the size, with a hard cap so no glyph can
    // ever paint oversized inside the pill.
    assert_contains('.pub-social-button svg { width: 16px; height: 16px; max-width: 16px; max-height: 16px;', $css, 'social icons are capped at 16x16');
    assert_contains('fill: var(--social-color)', $css, 'brand marks are filled in their brand colour');
    // Every mark is a solid brand path on the 24x24 brand grid, with no inline
    // sizing that could beat the stylesheet.
    preg_match_all('#<svg[^>]*>#', $footer, $svgs);
    assert_true(count($svgs[0]) >= 7, 'every channel ships an icon');
    foreach ($svgs[0] as $svg) {
        assert_contains('viewBox="0 0 24 24"', $svg, 'brand marks use the 24x24 grid: ' . $svg);
        assert_false((bool) preg_match('/\swidth=/', $svg), 'social svg must not carry inline width: ' . $svg);
        assert_false((bool) preg_match('/\sheight=/', $svg), 'social svg must not carry inline height: ' . $svg);
        assert_contains('aria-hidden="true"', $svg, 'decorative marks are hidden from assistive tech');
    }
    // Official glyph signatures (first path command of each brand mark) — these
    // are the real logos, not hand-drawn approximations.
    $signatures = [
        'Facebook' => 'M9.101 23.691v-7.98H6.627',
        'X' => 'M18.901 1.153h3.68l-8.04 9.19L24 22.846',
        'LinkedIn' => 'M20.447 20.452h-3.554v-5.569',
        'WhatsApp' => 'M17.472 14.382c-.297-.149-1.758-.867',
        'YouTube' => 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136',
        'Telegram' => 'M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12',
    ];
    foreach ($signatures as $label => $needle) {
        assert_contains($needle, $footer, "{$label} uses its official brand glyph");
    }
    // The old stroke-drawn placeholders are gone.
    assert_false(str_contains($footer, 'social-icon-dot'), 'the hand-drawn Instagram approximation is replaced');
    assert_false(str_contains($css, 'stroke-width: 1.8'), 'social marks no longer rely on stroke drawing');
});

test('auth pages are routed and protected-dashboard routes redirect to login', function () {
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    foreach (['login', 'register', 'forgot-password', 'logout', 'account', 'access-denied', 'admin/login'] as $path) {
        assert_contains("\$route['{$path}']", $routes, "auth route '$path' must exist");
    }
    $core = file_get_contents(FCPATH . 'application/core/MY_Controller.php');
    assert_contains('function requireLogin', $core);
    assert_contains("redirect('/login')", $core, 'requireLogin redirects visitors to /login');
    assert_contains('function requireAdminPage', $core);
    assert_contains("redirect('/access-denied')", $core, 'requireAdminPage redirects non-admins to /access-denied');
    foreach (['Welcome', 'Workspace', 'Paper', 'Admin', 'Lang_learn', 'Leads', 'Execution', 'Brokers', 'Risk_center', 'Journal', 'Notifications', 'Strategy_lab'] as $name) {
        $src = file_get_contents(FCPATH . 'application/controllers/' . $name . '.php');
        assert_contains('extends App_Controller', $src, "$name must extend App_Controller (login gate)");
    }
    // Sports and Football are the deliberate soft-gate exception: the read
    // pages render their shell plus an in-page sign-in prompt for guests
    // (optionalLogin, not requireLogin), so they must NOT hard-redirect.
    foreach (['Sports', 'Football'] as $name) {
        $src = file_get_contents(FCPATH . 'application/controllers/' . $name . '.php');
        assert_contains('extends MY_Controller', $src, "$name must extend MY_Controller (soft sign-in gate)");
        assert_contains('optionalLogin', $src, "$name must use the non-redirecting optionalLogin gate");
    }
    $admin = file_get_contents(FCPATH . 'application/controllers/Admin.php');
    assert_contains('requireAdminPage', $admin, 'admin controller enforces the super-admin gate');
});

test('login/register/forgot forms submit to their real actions with CSRF', function () {
    $login = file_get_contents(FCPATH . 'application/views/auth/login.php');
    assert_contains('action="/login/submit"', $login);
    assert_contains('name="csrf_token"', $login);
    $register = file_get_contents(FCPATH . 'application/views/auth/register.php');
    assert_contains('action="/register/submit"', $register);
    assert_contains('name="csrf_token"', $register);
    $forgot = file_get_contents(FCPATH . 'application/views/auth/forgot.php');
    assert_contains('action="/forgot-password/submit"', $forgot);
    assert_contains('name="csrf_token"', $forgot);
});

test('inline icon svgs are sized twice over: intrinsic floor plus a wrapper rule', function () {
    // An <svg> that has a viewBox but no width/height renders at the browser's
    // default replaced-element box (~150-300px). The dashboard icon helper is
    // emitted into many wrappers, so an icon blows up whenever one of them
    // forgets its CSS size — that is how /app/trading shipped a shield icon the
    // size of a panel. Guard both halves: the shared markup carries an intrinsic
    // floor, and every icon wrapper keeps an explicit pixel size (CSS wins, so
    // the floor never changes an icon that is already styled).
    $css = (string) file_get_contents(FCPATH . 'assets/css/ai_workforce.css');
    assert_contains('.btn svg { width: 18px; height: 18px; flex: none; }', $css, 'design system sizes button icons');
    assert_contains('button svg, a.btn svg, .notice svg { max-width: 18px; max-height: 18px;', $css, 'generic cap on icons inside buttons');
    assert_contains('.kp-card .kp-ic svg { width: 24px; height: 24px; }', $css, 'kpi card icons sized');
    assert_contains('.empty-state svg { width: 32px; height: 32px;', $css, 'empty-state icons sized');

    $views = [
        'application/views/trading/index.php',
        'application/views/workspace/index.php',
        'application/views/workforce/index.php',
        'application/views/admin/layout/header.php',
        'application/views/agent_platform/index.php',
        'application/views/multiplier/index.php',
    ];
    foreach ($views as $rel) {
        $src = (string) file_get_contents(FCPATH . $rel);
        assert_true($src !== '', $rel . ' is readable');
        if (!preg_match('/\$ic\s*=\s*\'<svg/', $src)) continue;
        assert_true(
            (bool) preg_match('/\$ic\s*=\s*\'<svg[^>]*\swidth="\d+"[^>]*\sheight="\d+"/', $src),
            $rel . ': $ic must carry width/height so a wrapper without a CSS rule cannot render a giant icon'
        );
    }

    // The two /app/trading wrappers that had no svg rule at all.
    $trading = (string) file_get_contents(FCPATH . 'application/views/trading/index.php');
    assert_contains('.risk-alert .ra-icon svg{width:20px;height:20px', $trading, 'risk-alert icon sized 20x20');
    assert_contains('.ks-banner svg{width:18px;height:18px', $trading, 'kill-switch banner icon sized 18x18');
    assert_contains('.quick-action .qa-icon svg{width:18px;height:18px}', $trading, 'quick-action icon still sized');
    foreach (['ra-icon', 'ks-banner', 'qa-icon'] as $cls) {
        assert_true(
            (bool) preg_match('/\.' . preg_quote($cls, '/') . '\s+svg\{[^}]*width:\s*\d+px/', $trading),
            '.' . $cls . ' svg keeps an explicit pixel width'
        );
    }
    // The chart svgs are meant to be big: they are sized by their own rules and
    // must not be confused with icons.
    assert_contains('.chart-svg{width:100%;height:240px', $trading, 'chart keeps its own size');
    assert_contains('.equity-curve{width:100%;height:160px', $trading, 'equity curve keeps its own size');
});

test('sidebar is pinned for the whole page, not just until its container ends', function () {
    $css = (string) file_get_contents(FCPATH . 'assets/css/ai_workforce.css');
    // The sidebar must be anchored to the viewport edge so it stays in view from
    // the top of the page through the very end of the scroll. position: sticky
    // was previously used but can stop pinning when an ancestor becomes a scroll
    // container or the grid area ends before the page bottom.
    assert_contains('.sidebar {', $css, 'sidebar rule exists');
    assert_contains('position: fixed;', $css, 'sidebar is pinned with position: fixed');
    assert_contains('left: 0;', $css, 'sidebar is anchored to the left edge');
    assert_contains('width: var(--sidebar-w);', $css, 'sidebar keeps the shared sidebar width');
    // The main column must stay clear of the fixed sidebar (out of flow item
    // must not disturb the two-column grid on desktop).
    assert_contains('.app-main { grid-column: 2;', $css, 'main column is pinned to column 2 on desktop');
    // Old sticky-only pinning must not come back for the sidebar.
    assert_false(
        (bool) preg_match('/\.sidebar\s*\{[^}]*position:\s*sticky/', $css),
        'sidebar must not rely on position: sticky'
    );
    // Full-width announcement / impersonation banners keep reading above the
    // pinned sidebar, but the mobile drawer still opens on top of them.
    assert_contains('.app-shell > .ann-bar,', $css, 'announcement banner is lifted above the sidebar');
    assert_contains('z-index: 35;', $css, 'banner layer sits between sidebar (30) and drawer (40)');
    assert_contains('.sidebar { display: none; position: fixed; z-index: 40; width: min(280px, 86vw);', $css, 'mobile drawer keeps its off-canvas behavior');
    assert_contains('.app-main { grid-column: auto; }', $css, 'main column returns to one column on mobile');
});

<?php
/**
 * 4-digit Security PIN + security question: stored on the user, collected at
 * signup, and readable from the Super Admin user profile only.
 */

test('identity schema ensure exposes recovery columns', function () {
    $db = platform()->model->db;
    \AIWorkforce\IdentitySchema::ensure($db);
    foreach (['security_pin', 'security_question', 'security_answer'] as $col) {
        assert_true(\AIWorkforce\IdentitySchema::has($db, $col), "users.$col exists");
    }
});

test('PIN, question and answer validation', function () {
    assert_true(\AIWorkforce\IdentitySchema::validPin('4242'));
    assert_true(\AIWorkforce\IdentitySchema::validPin(' 12-34 '));
    assert_equals('1234', \AIWorkforce\IdentitySchema::normalizePin(' 12-34 '));
    assert_false(\AIWorkforce\IdentitySchema::validPin('12'));
    assert_false(\AIWorkforce\IdentitySchema::validPin('12ab'));
    assert_true(\AIWorkforce\IdentitySchema::validQuestion('What city were you born in?'));
    assert_false(\AIWorkforce\IdentitySchema::validQuestion('short'));
    assert_true(\AIWorkforce\IdentitySchema::validAnswer('Lagos'));
    assert_false(\AIWorkforce\IdentitySchema::validAnswer('x'));
    $ok = \AIWorkforce\IdentitySchema::fromPostedRecovery('4242', 'What city were you born in?', '', 'Lagos');
    assert_not_null($ok);
    assert_equals('4242', $ok['security_pin']);
    $custom = \AIWorkforce\IdentitySchema::fromPostedRecovery('9876', '__custom__', 'What street did you grow up on?', 'Broad Street');
    assert_not_null($custom);
    assert_equals('What street did you grow up on?', $custom['security_question']);
    assert_null(\AIWorkforce\IdentitySchema::fromPostedRecovery('12', 'What city were you born in?', '', 'Lagos'));
});

test('createUser and updateUser persist PIN, question and answer', function () {
    $repo = platform()->model->identity;
    $now = gmdate('c');
    $user = $repo->createUser([
        'email' => 'pin-' . uniqid() . '@example.com',
        'password_hash' => password_hash('long-password-123456', PASSWORD_DEFAULT),
        'display_name' => 'Pin User',
        'security_pin' => '4242',
        'security_question' => 'What city were you born in?',
        'security_answer' => 'Lagos',
        'active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $fresh = $repo->findUserById((int) $user['id']);
    assert_equals('4242', (string) ($fresh['security_pin'] ?? ''));
    assert_equals('What city were you born in?', (string) ($fresh['security_question'] ?? ''));
    assert_equals('Lagos', (string) ($fresh['security_answer'] ?? ''));
    $repo->updateUser((int) $user['id'], [
        'security_pin' => '9876',
        'security_question' => 'What is the name of your first pet?',
        'security_answer' => 'Milo',
    ]);
    $again = $repo->findUserById((int) $user['id']);
    assert_equals('9876', (string) ($again['security_pin'] ?? ''));
    assert_equals('What is the name of your first pet?', (string) ($again['security_question'] ?? ''));
    assert_equals('Milo', (string) ($again['security_answer'] ?? ''));
});

test('authenticate and publicUser strip recovery secrets unless Super Admin asks', function () {
    $repo = platform()->model->identity;
    $now = gmdate('c');
    $email = 'pin-auth-' . uniqid() . '@example.com';
    $pass = 'long-password-123456';
    $user = $repo->createUser([
        'email' => $email,
        'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
        'display_name' => 'Pin Auth',
        'security_pin' => '4242',
        'security_question' => 'What city were you born in?',
        'security_answer' => 'Abuja',
        'active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $authed = platform()->identity->authenticate($email, $pass);
    assert_not_null($authed);
    assert_false(isset($authed['password_hash']));
    assert_false(isset($authed['security_pin']), 'session identity never carries the PIN');
    assert_false(isset($authed['security_question']));
    assert_false(isset($authed['security_answer']));

    $portal = new \AIWorkforce\AdminPortal(platform()->model);
    $row = $repo->findUserById((int) $user['id']);
    $hidden = $portal->publicUser($row, false);
    assert_false(isset($hidden['security_pin']));
    assert_false(isset($hidden['security_question']));
    assert_false(isset($hidden['security_answer']));
    assert_false(isset($hidden['password_hash']));
    $shown = $portal->publicUser($row, true);
    assert_equals('4242', (string) ($shown['security_pin'] ?? ''));
    assert_equals('What city were you born in?', (string) ($shown['security_question'] ?? ''));
    assert_equals('Abuja', (string) ($shown['security_answer'] ?? ''));
    assert_false(isset($shown['password_hash']));
});

test('register, account and admin profile collect or reveal recovery fields', function () {
    $register = file_get_contents(FCPATH . 'application/views/auth/register.php');
    assert_contains('name="security_pin"', $register);
    assert_contains('id="reg-pin"', $register);
    assert_contains('name="security_question"', $register);
    assert_contains('name="security_answer"', $register);
    $account = file_get_contents(FCPATH . 'application/views/auth/account.php');
    assert_contains('action="/account/recovery"', $account);
    assert_contains('name="security_pin"', $account);
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    assert_contains("\$route['account/recovery'] = 'auth/update_recovery';", $routes);
    $auth = file_get_contents(FCPATH . 'application/controllers/Auth.php');
    assert_contains('public function update_recovery()', $auth);
    assert_contains("'security_pin' => \$recovery['security_pin']", $auth);
    $admin = file_get_contents(FCPATH . 'application/controllers/Admin.php');
    assert_contains('findPublicUser((int) $id, $this->isSuperAdmin($actor))', $admin);
    $show = file_get_contents(FCPATH . 'application/views/admin/users/show.php');
    assert_contains('id="identity-recovery"', $show);
    assert_contains('4-digit Security PIN', $show);
    assert_contains('6-digit Identification Code', $show);
    assert_contains('Security question', $show);
    $index = file_get_contents(FCPATH . 'application/views/admin/users/index.php');
    assert_false(str_contains($index, 'security_pin'), 'directory list does not print PINs');
});

<?php
/**
 * Signup and contact share phone + address. The values persist on the user
 * row, pre-fill the public contact form, and the office map always renders.
 */

test('identity schema ensure exposes phone and address columns', function () {
    $db = platform()->model->db;
    \AIWorkforce\IdentitySchema::ensure($db);
    assert_true(\AIWorkforce\IdentitySchema::has($db, 'phone'), 'users.phone exists');
    assert_true(\AIWorkforce\IdentitySchema::has($db, 'address'), 'users.address exists');
});

test('phone and address validation accepts real numbers and rejects junk', function () {
    assert_true(\AIWorkforce\IdentitySchema::validPhone('+234 800 000 0000'));
    assert_true(\AIWorkforce\IdentitySchema::validPhone('08031234567'));
    assert_false(\AIWorkforce\IdentitySchema::validPhone('123'));
    assert_false(\AIWorkforce\IdentitySchema::validPhone('not-a-phone'));
    assert_true(\AIWorkforce\IdentitySchema::validAddress('12 Adeola Odeku Street, Victoria Island, Lagos'));
    assert_false(\AIWorkforce\IdentitySchema::validAddress('x'));
    assert_equals('+234 800 000 0000', \AIWorkforce\IdentitySchema::normalizePhone("  +234   800  000  0000 "));
});

test('createUser and updateUser persist phone and address', function () {
    $repo = platform()->model->identity;
    $now = gmdate('c');
    $user = $repo->createUser([
        'email' => 'contact-' . uniqid() . '@example.com',
        'password_hash' => password_hash('long-password-123456', PASSWORD_DEFAULT),
        'display_name' => 'Contact User',
        'phone' => '+234 1555 0100',
        'address' => '12 Adeola Odeku Street, Lagos',
        'active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $fresh = $repo->findUserById((int) $user['id']);
    assert_equals('+234 1555 0100', (string) ($fresh['phone'] ?? ''));
    assert_equals('12 Adeola Odeku Street, Lagos', (string) ($fresh['address'] ?? ''));
    $repo->updateUser((int) $user['id'], ['phone' => '+234 1555 0199', 'address' => 'Plot 4, Wuse II, Abuja']);
    $again = $repo->findUserById((int) $user['id']);
    assert_equals('+234 1555 0199', (string) ($again['phone'] ?? ''));
    assert_equals('Plot 4, Wuse II, Abuja', (string) ($again['address'] ?? ''));
});

test('register form collects phone and address', function () {
    $register = file_get_contents(FCPATH . 'application/views/auth/register.php');
    assert_contains('name="phone"', $register);
    assert_contains('id="reg-phone"', $register);
    assert_contains('name="address"', $register);
    assert_contains('id="reg-address"', $register);
    $auth = file_get_contents(FCPATH . 'application/controllers/Auth.php');
    assert_contains("input->post('phone')", $auth);
    assert_contains("input->post('address')", $auth);
    assert_contains("'phone' => \$phone", $auth);
    assert_contains("'address' => \$address", $auth);
});

test('contact page shows office phone, address, visitor fields and a map', function () {
    $view = file_get_contents(FCPATH . 'application/views/site/contact.php');
    assert_contains('/assets/images/contact-office.jpg', $view);
    assert_true(is_file(FCPATH . 'assets/images/contact-office.jpg'), 'contact office image is present');
    assert_contains('name="phone"', $view);
    assert_contains('name="address"', $view);
    assert_contains('openstreetmap.org/export/embed.html', file_get_contents(FCPATH . 'application/controllers/Site.php'));
    assert_contains('mapSrc', $view);
    assert_contains('map-frame--side', $view);
    assert_contains('formPhone', $view);
    assert_contains('formAddress', $view);
    $site = file_get_contents(FCPATH . 'application/controllers/Site.php');
    assert_contains("input->post('phone')", $site);
    assert_contains("input->post('address')", $site);
    assert_contains('VP_CONTACT_LAT', $site);
    assert_contains('notifyOperator($name, $email, $phone, $address, $message)', $site);
});

test('account page edits phone and address through /account/contact', function () {
    $view = file_get_contents(FCPATH . 'application/views/auth/account.php');
    assert_contains('action="/account/contact"', $view);
    assert_contains('name="phone"', $view);
    assert_contains('name="address"', $view);
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    assert_contains("\$route['account/contact'] = 'auth/update_contact';", $routes);
    $auth = file_get_contents(FCPATH . 'application/controllers/Auth.php');
    assert_contains('public function update_contact()', $auth);
});

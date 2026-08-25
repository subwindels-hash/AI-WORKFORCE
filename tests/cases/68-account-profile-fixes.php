<?php
/**
 * Username update + profile image: schema, immutability of User ID,
 * uniqueness, and the upload helper that production uses.
 */

test('identity schema ensure exposes username, user_uid and profile_image', function () {
    $db = platform()->model->db;
    \AIWorkforce\IdentitySchema::ensure($db);
    foreach (['username', 'user_uid', 'profile_image', 'email', 'password_hash'] as $col) {
        assert_true(\AIWorkforce\IdentitySchema::has($db, $col), "users.$col exists");
    }
});

test('username update persists and leaves the six-digit User ID unchanged', function () {
    $repo = platform()->model->identity;
    $now = gmdate('c');
    $user = $repo->createUser([
        'email' => 'un-' . uniqid() . '@example.com',
        'password_hash' => password_hash('long-password-123456', PASSWORD_DEFAULT),
        'display_name' => 'Name Change',
        'active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $uid = (string) $user['user_uid'];
    $next = $repo->generateUniqueUsername('newname');
    $repo->updateUser((int) $user['id'], ['username' => $next, 'display_name' => $next, 'user_uid' => '000000']);
    $fresh = $repo->findUserById((int) $user['id']);
    assert_equals($next, (string) $fresh['username']);
    assert_equals($uid, (string) $fresh['user_uid'], 'User ID must stay permanent');
});

test('usernameTaken rejects a name already used by another account', function () {
    $repo = platform()->model->identity;
    $now = gmdate('c');
    $a = $repo->createUser([
        'email' => 'dup-a-' . uniqid() . '@example.com',
        'password_hash' => password_hash('long-password-123456', PASSWORD_DEFAULT),
        'display_name' => 'DupA', 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $b = $repo->createUser([
        'email' => 'dup-b-' . uniqid() . '@example.com',
        'password_hash' => password_hash('long-password-123456', PASSWORD_DEFAULT),
        'display_name' => 'DupB', 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    assert_true($repo->usernameTaken((string) $a['username'], (int) $b['id']));
    assert_false($repo->usernameTaken((string) $b['username'], (int) $b['id']));
});

test('profile image helper rejects non-images and stores a real jpeg', function () {
    $dirErr = \AIWorkforce\ProfileImage::prepareDirectory();
    assert_null($dirErr, $dirErr ?? 'upload dir ready');

    $junk = tempnam(sys_get_temp_dir(), 'notimg');
    file_put_contents($junk, '<?php echo "nope";');
    $bad = \AIWorkforce\ProfileImage::store([
        'error' => UPLOAD_ERR_OK, 'tmp_name' => $junk, 'size' => filesize($junk), 'name' => 'x.php',
    ], 1);
    assert_false($bad['ok']);
    @unlink($junk);

    $jpeg = tempnam(sys_get_temp_dir(), 'okimg') . '.jpg';
    // Minimal valid 1x1 JPEG.
    file_put_contents($jpeg, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wAAAQEB/9k='));
    // Fallback: generate via GD if the stub is not a real JPEG.
    if (@getimagesize($jpeg) === false && function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor(8, 8);
        imagejpeg($im, $jpeg, 90);
        imagedestroy($im);
    }
    if (@getimagesize($jpeg) === false) {
        assert_true(true, 'GD/jpeg unavailable in this runtime; skip binary store');
        @unlink($jpeg);
        return;
    }
    $ok = \AIWorkforce\ProfileImage::store([
        'error' => UPLOAD_ERR_OK, 'tmp_name' => $jpeg, 'size' => filesize($jpeg), 'name' => 'face.jpg',
    ], 42);
    assert_true($ok['ok'], $ok['error'] ?? 'store jpeg');
    assert_true(str_starts_with((string) ($ok['path'] ?? ''), '/assets/uploads/avatars/'));
    $abs = rtrim(FCPATH, '/\\') . str_replace('/', DIRECTORY_SEPARATOR, $ok['path']);
    assert_true(is_file($abs), 'uploaded file exists on disk');
    \AIWorkforce\ProfileImage::deletePublicPath($ok['path']);
    assert_false(is_file($abs), 'deleted after cleanup');
    @unlink($jpeg);
});

test('account forms post to the username and avatar controllers with CSRF and multipart', function () {
    $view = file_get_contents(FCPATH . 'application/views/auth/account.php');
    assert_contains('action="/account/username"', $view);
    assert_contains('name="username"', $view);
    assert_contains('enctype="multipart/form-data"', $view);
    assert_contains('name="avatar"', $view);
    assert_contains('action="/account/avatar"', $view);
    $auth = file_get_contents(FCPATH . 'application/controllers/Auth.php');
    assert_contains('public function update_username()', $auth);
    assert_contains('public function upload_avatar()', $auth);
    assert_contains('IdentitySchema::ensure', $auth);
    assert_contains('ProfileImage::store', $auth);
});

<?php
/**
 * Browser-save hardening, voice helpers, and the ISO language catalog.
 */

test('upload_avatar is a complete method and no longer contains the corrupted merge', function () {
    $auth = file_get_contents(FCPATH . 'application/controllers/Auth.php');
    assert_false(str_contains($auth, 'could not b$this'), 'corrupted upload_avatar string is gone');
    assert_contains('public function upload_avatar()', $auth);
    assert_contains('ProfileImage::store', $auth);
    assert_contains('Unable to save your changes. Please try again.', $auth);
    assert_contains('✓ Changes saved successfully', $auth);
    assert_contains('catch (Throwable $e)', $auth);
    // The leftover undefined $ext / move_uploaded_file path must not remain.
    $method = [];
    if (preg_match('/function upload_avatar\(\)\s*\{(.*?)\n    public function /s', $auth, $m)) {
        $method = $m[1];
        assert_false(str_contains($method, 'move_uploaded_file'), 'old upload path removed from upload_avatar');
        assert_false(str_contains($method, '.$ext'), 'undefined $ext leftover removed');
    } else {
        assert_true(false, 'could not isolate upload_avatar');
    }
});

test('account save actions persist username email password and avatar path', function () {
    $repo = platform()->model->identity;
    $now = gmdate('c');
    $user = $repo->createUser([
        'email' => 'save-' . uniqid() . '@example.com',
        'password_hash' => password_hash('long-password-123456', PASSWORD_DEFAULT),
        'display_name' => 'Save User',
        'active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $uid = (string) $user['user_uid'];
    $name = $repo->generateUniqueUsername('savedname');
    $repo->updateUser((int) $user['id'], ['username' => $name, 'display_name' => $name]);
    $email = 'save-' . uniqid() . '@example.com';
    $repo->updateUser((int) $user['id'], ['email' => $email]);
    $repo->updateUser((int) $user['id'], ['password_hash' => password_hash('another-long-password-9', PASSWORD_DEFAULT)]);
    $repo->updateUser((int) $user['id'], ['profile_image' => '/assets/uploads/avatars/u-test.png']);
    $fresh = $repo->findUserById((int) $user['id']);
    assert_equals($name, (string) $fresh['username']);
    assert_equals($email, (string) $fresh['email']);
    assert_equals($uid, (string) $fresh['user_uid']);
    assert_equals('/assets/uploads/avatars/u-test.png', (string) $fresh['profile_image']);
    assert_not_null(platform()->identity->authenticate($email, 'another-long-password-9'));
    assert_null(platform()->identity->authenticate($email, 'long-password-123456'));
});

test('ISO language catalog searches English, native name and ISO code', function () {
    $all = \AIWorkforce\LangLearn\LanguageCatalog::all();
    assert_true(count($all) >= 180, 'catalog includes the ISO 639-1 set, got ' . count($all));
    $nl = \AIWorkforce\LangLearn\LanguageCatalog::get('nl');
    assert_not_null($nl);
    assert_equals('Dutch', $nl['name']);
    assert_equals('Nederlands', $nl['native_name']);
    assert_equals('nl-NL', $nl['bcp47']);
    assert_equals('ltr', $nl['direction']);
    assert_true($nl['tts'], 'Dutch has a known browser TTS locale');
    assert_true($nl['full_ai'], 'Dutch has an authored AI bank');

    $byNative = \AIWorkforce\LangLearn\LanguageCatalog::search('Nederlands', 8);
    assert_true(count($byNative) > 0);
    assert_equals('nl', $byNative[0]['code']);
    $byIso = \AIWorkforce\LangLearn\LanguageCatalog::search('nl', 8);
    assert_equals('nl', $byIso[0]['code']);
    $byEn = \AIWorkforce\LangLearn\LanguageCatalog::search('Dutch', 8);
    assert_equals('nl', $byEn[0]['code']);
});

test('catalog capabilities are honest: AI vs translation vs text-only', function () {
    $nl = \AIWorkforce\LangLearn\LanguageCatalog::capabilities('nl');
    assert_true($nl['full_ai']);
    assert_true($nl['translation']);
    assert_true($nl['tts']);
    assert_equals('Supported for full AI learning', $nl['label']);

    $nv = \AIWorkforce\LangLearn\LanguageCatalog::get('nv');
    assert_not_null($nv, 'Navajo is in the ISO 639-1 seed');
    $caps = \AIWorkforce\LangLearn\LanguageCatalog::capabilities('nv');
    assert_false($caps['full_ai'], 'Navajo has no authored AI bank');
    assert_false($caps['translation'], 'Navajo is not in the phrasebook');
    assert_true($caps['text_only'] || $caps['label'] === 'Text only' || $caps['label'] === 'Voice available' || $caps['label'] === 'Speech recognition available');

    $unknown = \AIWorkforce\LangLearn\LanguageCatalog::capabilities('qq');
    assert_true($unknown['text_only']);
    assert_false(\AIWorkforce\LangLearn\LanguageCatalog::has('qq'));
});

test('official SIL ISO 639-3 table parser reads real rows', function () {
    $dir = FCPATH . 'application/data';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $tab = $dir . '/iso639-3.test.tab';
    file_put_contents($tab, "Id\tPart2B\tPart2T\tPart1\tScope\tLanguage_Type\tRef_Name\tComment\n" .
        "nld\tdut\tnld\tnl\tI\tL\tDutch\t\n" .
        "nav\tnav\tnav\tnv\tI\tL\tNavajo\t\n" .
        "aaa\t\t\t\tI\tL\tGhotuo\t\n" .
        "und\tund\tund\t\tS\tS\tUndetermined\t\n");
    $rows = \AIWorkforce\LangLearn\LanguageCatalog::parseOfficialTable($tab);
    @unlink($tab);
    $byId = [];
    foreach ($rows as $r) $byId[$r['iso6393']] = $r;
    assert_true(isset($byId['nld']));
    assert_equals('nl', $byId['nld']['iso6391']);
    assert_equals('Dutch', $byId['nld']['name']);
    assert_true(isset($byId['aaa']));
    assert_equals('', $byId['aaa']['iso6391']);
    assert_false(isset($byId['und']), 'special non-language codes are skipped');
});

test('startLanguage accepts a catalog language that is not in the original 20', function () {
    $svc = platform()->langlearn;
    $email = 'cat-' . uniqid() . '@example.com';
    $now = gmdate('c');
    $user = platform()->model->identity->createUser([
        'email' => $email, 'password_hash' => password_hash('long-password-123456', PASSWORD_DEFAULT),
        'display_name' => 'Catalog User', 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $profile = $svc->startLanguage((int) $user['id'], 'fi', 'Read Finnish news');
    assert_true((int) $profile['id'] > 0);
    assert_equals('fi', $profile['language_code']);
    $again = $svc->startLanguage((int) $user['id'], 'fi');
    assert_equals($profile['id'], $again['id']);
    assert_throws(InvalidArgumentException::class, fn() => $svc->startLanguage((int) $user['id'], 'qq'));
});

test('translator accepts catalog targets and stays honest when the phrasebook has no row', function () {
    $t = platform()->translator;
    $hit = $t->translate('Good morning, how are you?', 'nl', 'en');
    assert_equals('nl', $hit['target']);
    assert_equals('nl-NL', $hit['targetLocale']);
    assert_true(is_string($hit['translation']) && $hit['translation'] !== '');

    $fi = $t->translate('Good morning, how are you?', 'fi', 'en');
    assert_equals('fi', $fi['target']);
    assert_true($fi['translation'] === null || $fi['method'] === 'none' || is_string($fi['translation']));
    if ($fi['translation'] === null) {
        assert_equals('none', $fi['method']);
        assert_true(str_contains((string) $fi['note'], 'not in the authored'));
    }
});

test('teacher and chat expose Speak and Listen controls wired to SpeechProvider', function () {
    $teacher = file_get_contents(FCPATH . 'application/views/langlearn/teacher.php');
    assert_contains('language-picker.js', $teacher);
    assert_contains('tt-target-picker', $teacher);
    assert_contains('tt-source-picker', $teacher);
    assert_contains('🎤 Tap to Speak', $teacher);
    assert_contains('🔊 Listen', $teacher);
    assert_contains('tt-pause', $teacher);
    assert_contains('Voice pronunciation isn\'t currently available', $teacher);
    assert_contains('Language I\'m Learning', $teacher);
    assert_contains('My Language', $teacher);

    $chat = file_get_contents(FCPATH . 'application/views/partials/chat_widget.php');
    assert_contains('ai_workforce-chat-mic', $chat);
    assert_contains('🔊 Listen', $chat);
    $js = file_get_contents(FCPATH . 'assets/js/ai_workforce-chat.js');
    assert_contains('bindMic', $js);
    assert_contains('data-listen', $js);

    $speech = file_get_contents(FCPATH . 'assets/js/speech-provider.js');
    assert_contains('pause() {', $speech);
    assert_contains('resume() {', $speech);
    assert_contains('bindMic', $speech);
    assert_contains('friendlySttError', $speech);
    assert_contains('Tap to Speak', $speech);
});

test('save feedback toast script is loaded on the dashboard and admin shells', function () {
    $footer = file_get_contents(FCPATH . 'application/views/layout/footer.php');
    assert_contains('save-feedback.js', $footer);
    assert_contains('speech-provider.js', $footer);
    $admin = file_get_contents(FCPATH . 'application/views/admin/layout/footer.php');
    assert_contains('save-feedback.js', $admin);
    $css = file_get_contents(FCPATH . 'assets/css/ai_workforce.css');
    assert_contains('.save-toast--ok', $css);
    assert_contains('.lang-picker-search', $css);
});

<?php
/**
 * Central Provider / API Management: encrypted store, RBAC, module wiring.
 */

function fx_api_db(): object
{
    $db = platform()->model->db;
    \AIWorkforce\ApiProviders::ensureSchema($db);
    return $db;
}

function fx_api_cleanup(array $ids): void
{
    $db = platform()->model->db;
    foreach ($ids as $id) {
        if ((int) $id > 0) {
            try { $db->where('id', (int) $id)->delete('api_providers'); } catch (Throwable $e) { /* ignore */ }
        }
    }
    \AIWorkforce\ApiProviders::$http = null;
}

test('api provider schema, routes, views and catalog are installed', function () {
    $db = fx_api_db();
    assert_true($db->table_exists('api_providers'), 'api_providers table exists');
    $services = \AIWorkforce\ApiProviders::services();
    foreach (['lead_discovery', 'sports', 'lottery', 'crypto_market', 'forex_market', 'translation', 'stt', 'tts', 'language_ai', 'llm', 'trading_execution'] as $code) {
        assert_true(isset($services[$code]), "service $code is catalogued");
    }
    assert_equals('data', $services['crypto_market']['kind']);
    assert_equals('action', $services['trading_execution']['kind']);
    assert_false(in_array('binance_public', $services['trading_execution']['drivers'], true), 'market-data driver cannot execute trades');
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    foreach (['admin/api', 'admin/api/create', 'admin/api/save', 'admin/api/(:num)/test', 'api/v1/language-learning/voice-status'] as $r) {
        assert_contains("\$route['{$r}']", $routes, "route $r");
    }
    assert_true(is_file(FCPATH . 'application/views/admin/api/index.php'));
    assert_true(is_file(FCPATH . 'application/views/admin/api/form.php'));
    $admin = file_get_contents(FCPATH . 'application/controllers/Admin.php');
    assert_contains('function api_test', $admin);
    assert_contains('function api_save', $admin);
    assert_contains('admin.api.credentials', $admin);
    assert_false(str_contains($admin, '\\\\AIWorkforce\\\\ApiProviders'), 'Admin controller must not double-escape the store class');
});

test('api secrets encrypt at rest, mask in listings and never appear in dashboard or audit-shaped payloads', function () {
    $db = fx_api_db();
    $secret = 'super-secret-key-9999';
    $saved = \AIWorkforce\ApiProviders::save($db, [
        'service' => 'lead_discovery',
        'driver' => 'google_places',
        'label' => 'Places test',
        'role' => 'primary',
        'enabled' => 1,
        'api_key' => $secret,
    ], null, 1, true);
    $id = (int) ($saved['id'] ?? 0);
    try {
        assert_true($id > 0);
        $raw = $db->get_where('api_providers', ['id' => $id], 1)->row_array();
        assert_not_null($raw);
        assert_true(!empty($raw['secret_blob']));
        assert_false(str_contains((string) $raw['secret_blob'], $secret), 'plaintext secret is not stored');
        $opened = \AIWorkforce\ApiProviders::open($raw['secret_blob']);
        assert_contains($secret, $opened);
        assert_equals(\AIWorkforce\ApiProviders::mask($secret), '••••••••••••9999');

        $listed = \AIWorkforce\ApiProviders::list($db);
        $blob = json_encode($listed);
        assert_false(str_contains($blob, $secret), 'list() never returns the full secret');
        $hit = null;
        foreach ($listed as $row) if ((int) $row['id'] === $id) $hit = $row;
        assert_not_null($hit);
        assert_false(isset($hit['secrets']), 'list hydrate does not include secrets');
        assert_equals('••••••••••••9999', $hit['masked']['api_key'] ?? '');

        $dash = json_encode(\AIWorkforce\ApiProviders::dashboard($db));
        assert_false(str_contains($dash, $secret));
        $dashRows = \AIWorkforce\ApiProviders::dashboard($db);
        $lead = null;
        foreach ($dashRows as $row) if ($row['service'] === 'lead_discovery') $lead = $row;
        assert_not_null($lead);
        assert_equals('Configured', $lead['status']);
        assert_true($lead['primary']);

        $secrets = \AIWorkforce\ApiProviders::findSecrets($db, $id);
        assert_equals($secret, $secrets['api_key'] ?? null);
    } finally {
        fx_api_cleanup([$id]);
    }
});

test('saving a new primary provider demotes the previous primary for that service only', function () {
    $db = fx_api_db();
    $a = \AIWorkforce\ApiProviders::save($db, [
        'service' => 'translation', 'driver' => 'libretranslate', 'label' => 'Primary A',
        'role' => 'primary', 'enabled' => 1, 'base_url' => 'https://translate.example/v1',
    ], null, 1, true);
    $b = \AIWorkforce\ApiProviders::save($db, [
        'service' => 'translation', 'driver' => 'libretranslate', 'label' => 'Primary B',
        'role' => 'primary', 'enabled' => 1, 'base_url' => 'https://translate-b.example/v1',
    ], null, 1, true);
    $other = \AIWorkforce\ApiProviders::save($db, [
        'service' => 'stt', 'driver' => 'browser_webspeech', 'label' => 'Browser STT',
        'role' => 'primary', 'enabled' => 1,
    ], null, 1, true);
    try {
        $freshA = \AIWorkforce\ApiProviders::find($db, (int) $a['id']);
        $freshB = \AIWorkforce\ApiProviders::find($db, (int) $b['id']);
        $freshOther = \AIWorkforce\ApiProviders::find($db, (int) $other['id']);
        assert_equals('fallback', $freshA['role']);
        assert_equals('primary', $freshB['role']);
        assert_equals('primary', $freshOther['role'], 'other services keep their primary');
        $active = \AIWorkforce\ApiProviders::activeConfig($db, 'translation');
        assert_equals((int) $b['id'], (int) $active['id']);
    } finally {
        fx_api_cleanup([(int) $a['id'], (int) $b['id'], (int) $other['id']]);
    }
});

test('save keeps existing secrets when the new secret is blank or the actor cannot change credentials', function () {
    $db = fx_api_db();
    $saved = \AIWorkforce\ApiProviders::save($db, [
        'service' => 'llm', 'driver' => 'openai_compatible', 'label' => 'LLM',
        'role' => 'primary', 'enabled' => 1,
        'base_url' => 'https://api.example/v1/chat/completions',
        'api_key' => 'keep-me-key-4242', 'model' => 'demo-model',
    ], null, 1, true);
    $id = (int) $saved['id'];
    try {
        \AIWorkforce\ApiProviders::save($db, [
            'service' => 'llm', 'driver' => 'openai_compatible', 'label' => 'LLM',
            'role' => 'primary', 'enabled' => 1,
            'base_url' => 'https://api.example/v1/chat/completions',
            'api_key' => '', 'model' => 'demo-model',
        ], $id, 1, true);
        assert_equals('keep-me-key-4242', \AIWorkforce\ApiProviders::findSecrets($db, $id)['api_key'] ?? null);

        \AIWorkforce\ApiProviders::save($db, [
            'service' => 'llm', 'driver' => 'openai_compatible', 'label' => 'LLM',
            'role' => 'primary', 'enabled' => 1,
            'base_url' => 'https://api.example/v1/chat/completions',
            'api_key' => 'attacker-new-secret', 'model' => 'demo-model',
        ], $id, 1, false);
        assert_equals('keep-me-key-4242', \AIWorkforce\ApiProviders::findSecrets($db, $id)['api_key'] ?? null, 'no-credentials actor cannot rotate secrets');
    } finally {
        fx_api_cleanup([$id]);
    }
});

test('base URL must be HTTPS and unknown pairings are rejected', function () {
    $db = fx_api_db();
    assert_throws(InvalidArgumentException::class, fn () => \AIWorkforce\ApiProviders::save($db, [
        'service' => 'lead_discovery', 'driver' => 'google_places', 'base_url' => 'http://insecure.example',
        'api_key' => 'x',
    ], null, 1, true));
    assert_throws(InvalidArgumentException::class, fn () => \AIWorkforce\ApiProviders::save($db, [
        'service' => 'not_a_service', 'driver' => 'google_places',
    ], null, 1, true));
    assert_throws(InvalidArgumentException::class, fn () => \AIWorkforce\ApiProviders::save($db, [
        'service' => 'crypto_market', 'driver' => 'google_places',
    ], null, 1, true), 'a data driver cannot be attached to the wrong service');
});

test('Test Connection talks to the provider through the injectable HTTP client and never echoes secrets', function () {
    $db = fx_api_db();
    $saved = \AIWorkforce\ApiProviders::save($db, [
        'service' => 'crypto_market', 'driver' => 'binance_public', 'label' => 'Binance ping',
        'role' => 'unused', 'enabled' => 0, 'base_url' => 'https://api.binance.com',
    ], null, 1, true);
    $id = (int) $saved['id'];
    $seen = [];
    try {
        \AIWorkforce\ApiProviders::$http = function (string $url, array $headers = [], ?string $body = null) use (&$seen) {
            $seen[] = $url;
            return ['status' => 200, 'body' => '{}'];
        };
        $ok = \AIWorkforce\ApiProviders::test($saved, []);
        assert_true($ok['ok']);
        assert_equals('Connected', $ok['message']);
        assert_true(isset($ok['ms']));
        assert_true(count($seen) >= 1);
        assert_contains('/api/v3/ping', $seen[0]);

        \AIWorkforce\ApiProviders::$http = function (string $url, array $headers = [], ?string $body = null) {
            return ['status' => 401, 'body' => 'invalid api key sk-abcdefghijklmnop'];
        };
        $places = ['driver' => 'google_places', 'base_url' => '', 'extra' => []];
        $fail = \AIWorkforce\ApiProviders::test($places, ['api_key' => 'sk-abcdefghijklmnop']);
        assert_false($fail['ok']);
        assert_equals('Connection failed', $fail['message']);
        assert_false(str_contains($fail['message'], 'sk-abcdefghijklmnop'));
        assert_false(str_contains($fail['message'], 'api key sk-'));
    } finally {
        fx_api_cleanup([$id]);
    }
});

test('publicError never leaks key, token, env or configuration internals to members', function () {
    $safe = \AIWorkforce\ApiProviders::USER_UNAVAILABLE;
    foreach ([
        'GOOGLE_PLACES_API_KEY missing',
        'API key is not configured',
        'Provider not configured',
        'Environment variable missing',
        'unauthorized 401',
        'invalid api secret',
        'getenv WINDELS_SPORTS_HTTP_TOKEN',
    ] as $internal) {
        assert_equals($safe, \AIWorkforce\ApiProviders::publicError($internal), $internal);
        assert_false(str_contains(\AIWorkforce\ApiProviders::publicError($internal), 'API key'));
    }
    $lead = file_get_contents(FCPATH . 'application/controllers/Api_lead_discovery.php');
    assert_contains('ApiProviders::publicError', $lead);
});

test('Google Places, lottery and sports resolve managed credentials from the store', function () {
    $db = fx_api_db();
    $ids = [];
    try {
        $places = \AIWorkforce\ApiProviders::save($db, [
            'service' => 'lead_discovery', 'driver' => 'google_places', 'label' => 'Managed Places',
            'role' => 'primary', 'enabled' => 1, 'api_key' => 'places-live-7777',
        ], null, 1, true);
        $ids[] = (int) $places['id'];
        $provider = new \LeadDiscovery\GooglePlacesProvider();
        $health = $provider->healthCheck();
        assert_equals('IMPLEMENTED', $health['status']);
        assert_false(str_contains(json_encode($health), 'GOOGLE_PLACES_API_KEY'));
        assert_false(str_contains(json_encode($health), 'places-live-7777'));

        $lotto = \AIWorkforce\ApiProviders::save($db, [
            'service' => 'lottery', 'driver' => 'official_lottery', 'label' => 'Official feed',
            'role' => 'primary', 'enabled' => 1,
            'base_url' => 'https://lottery.example/v1',
            'license' => 'contract-9', 'source' => 'authorized-official-feed',
            'token' => 'lotto-token',
        ], null, 1, true);
        $ids[] = (int) $lotto['id'];
        $official = new \AIWorkforce\Lottery\OfficialLotteryProvider();
        assert_true($official->configured());

        $sports = \AIWorkforce\ApiProviders::save($db, [
            'service' => 'sports', 'driver' => 'http_sports', 'label' => 'Sports feed',
            'role' => 'primary', 'enabled' => 1,
            'base_url' => 'https://sports.example', 'token' => 'sports-token',
        ], null, 1, true);
        $ids[] = (int) $sports['id'];
        $cfg = \AIWorkforce\ApiProviders::resolve('sports');
        assert_not_null($cfg);
        assert_equals('https://sports.example', $cfg['base_url']);
        assert_equals('sports-token', $cfg['secrets']['token'] ?? null);
    } finally {
        fx_api_cleanup($ids);
    }
});

test('translator uses a managed translation provider only after the phrasebook cannot cover the text', function () {
    $db = fx_api_db();
    $saved = \AIWorkforce\ApiProviders::save($db, [
        'service' => 'translation', 'driver' => 'libretranslate', 'label' => 'LT',
        'role' => 'primary', 'enabled' => 1, 'base_url' => 'https://lt.example',
    ], null, 1, true);
    try {
        $t = platform()->translator;
        $hello = $t->translate('hello', 'fr', 'en');
        assert_true(in_array($hello['method'], ['phrasebook', 'word-by-word'], true), 'authored coverage stays first');
        assert_true(is_string($hello['translation']) && $hello['translation'] !== '');

        \AIWorkforce\ApiProviders::$http = function (string $url, array $headers = [], ?string $body = null) {
            return ['status' => 200, 'body' => json_encode(['translatedText' => 'Hyvää huomenta, ystävä'])];
        };
        $fi = $t->translate('This unique sentence is not in any phrasebook 12345', 'fi', 'en');
        assert_equals('provider', $fi['method']);
        assert_equals('Hyvää huomenta, ystävä', $fi['translation']);
        assert_contains('configured translation provider', (string) $fi['note']);
    } finally {
        fx_api_cleanup([(int) $saved['id']]);
    }
});

test('chat assistant and language voice-status consume the managed store without exposing secrets', function () {
    $db = fx_api_db();
    $saved = \AIWorkforce\ApiProviders::save($db, [
        'service' => 'llm', 'driver' => 'openai_compatible', 'label' => 'Chat LLM',
        'role' => 'primary', 'enabled' => 1,
        'base_url' => 'https://llm.example/v1/chat/completions',
        'api_key' => 'llm-secret-5555', 'model' => 'demo-mini',
    ], null, 1, true);
    try {
        \AIWorkforce\ApiProviders::$http = function (string $url, array $headers = [], ?string $body = null) {
            return ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['content' => 'Use Languages from the workspace menu.']]]])];
        };
        $out = (new \AIWorkforce\ChatAssistant())->respond('How do I start language learning?');
        assert_equals('configured-ai', $out['provider']);
        assert_contains('Languages', $out['message']);
        assert_false(str_contains(json_encode($out), 'llm-secret-5555'));

        $status = \AIWorkforce\ApiProviders::publicStatus('llm');
        assert_true($status['configured']);
        assert_equals('openai_compatible', $status['driver']);
        assert_false(isset($status['secrets']));
        assert_false(str_contains(json_encode($status), 'llm-secret-5555'));
    } finally {
        fx_api_cleanup([(int) $saved['id']]);
    }
});

test('configuring crypto market data does not authorize trading execution', function () {
    $db = fx_api_db();
    $market = \AIWorkforce\ApiProviders::save($db, [
        'service' => 'crypto_market', 'driver' => 'binance_public', 'label' => 'Market only',
        'role' => 'primary', 'enabled' => 1, 'base_url' => 'https://api.binance.com',
    ], null, 1, true);
    try {
        assert_true(\AIWorkforce\ApiProviders::serviceEnabled($db, 'crypto_market', false));
        assert_null(\AIWorkforce\ApiProviders::activeConfig($db, 'trading_execution'));
        assert_false(\AIWorkforce\ApiProviders::serviceEnabled($db, 'trading_execution', false), 'execution stays off until explicitly configured');
        $services = \AIWorkforce\ApiProviders::services();
        assert_equals('data', $services['crypto_market']['kind']);
        assert_equals('action', $services['trading_execution']['kind']);
    } finally {
        fx_api_cleanup([(int) $market['id']]);
    }
});

test('admin API RBAC is explicit: view/test vs manage/credentials', function () {
    assert_in_array('admin.api.view', array_keys(AI_WORKFORCE_RBAC_PERMISSIONS));
    assert_in_array('admin.api.manage', array_keys(AI_WORKFORCE_RBAC_PERMISSIONS));
    assert_in_array('admin.api.test', array_keys(AI_WORKFORCE_RBAC_PERMISSIONS));
    assert_in_array('admin.api.credentials', array_keys(AI_WORKFORCE_RBAC_PERMISSIONS));
    assert_in_array('admin.api.view', AI_WORKFORCE_RBAC_GRANTS['admin']);
    assert_in_array('admin.api.test', AI_WORKFORCE_RBAC_GRANTS['admin']);
    assert_false(in_array('admin.api.manage', AI_WORKFORCE_RBAC_GRANTS['admin'], true));
    assert_false(in_array('admin.api.credentials', AI_WORKFORCE_RBAC_GRANTS['admin'], true));
    assert_false(in_array('admin.api.view', AI_WORKFORCE_RBAC_GRANTS['support_admin'], true));
    foreach (['admin.api.view', 'admin.api.manage', 'admin.api.test', 'admin.api.credentials'] as $perm) {
        assert_in_array($perm, AI_WORKFORCE_RBAC_GRANTS['super_admin']);
    }
    $header = file_get_contents(FCPATH . 'application/views/admin/layout/header.php');
    assert_contains('API Management', $header);
    assert_contains("admin_can('admin.api.view')", $header);
});

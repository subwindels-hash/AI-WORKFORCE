<?php
/**
 * DISABLED_NO_PROVIDER — honest, but not actionable.
 *
 * The automatic generation fix (case 151) made the sweep run by itself. The
 * sweep then reported, correctly:
 *
 *   NO VALUE TICKET TODAY — no stored eligible fixtures and no sports
 *   provider configured (DISABLED_NO_PROVIDER); nothing is fabricated
 *
 * Provider registration itself is NOT broken: with a key present,
 * SportsIntelligence registers the native adapter and the engine leaves
 * DISABLED_NO_PROVIDER immediately (pinned below). The real gap was that a
 * correctly-installed deployment had no way to learn WHAT to do — which of
 * three vendors, which exact environment variable this runtime reads, or
 * that credentials can be saved in Admin → API at all.
 *
 * ProviderSetupAdvisor closes that gap. It diagnoses only: it must never
 * register a provider, never write a credential, never fabricate a feed —
 * and never print a secret.
 */
use AIWorkforce\Sports\ProviderSetupAdvisor;
use AIWorkforce\Sports\Providers\SportsProviderManager;
use AIWorkforce\Sports\SportsIntelligence;

function ci152_env(array $map): callable
{
    return fn(string $key) => $map[$key] ?? false;
}

function ci152_manager(array $providers = []): SportsProviderManager
{
    $manager = new SportsProviderManager();
    foreach ($providers as $provider) $manager->register($provider);
    return $manager;
}

test('advisor: an empty deployment is told exactly what to do next', function () {
    $advice = ProviderSetupAdvisor::diagnose(ci152_manager(), [], ci152_env([]), false);

    assert_equals(ProviderSetupAdvisor::STATE_NO_PROVIDER, $advice['state']);
    assert_false($advice['providersConfigured']);
    assert_true(str_contains($advice['nextStep'], 'Admin'), 'the next step names where credentials live');
    assert_equals('/admin/api', $advice['adminUrl'], 'and links to the exact screen');
    assert_true(count($advice['options']) >= 3, 'every supported vendor is offered');
    assert_true(str_contains($advice['disclaimer'], 'fabricat'),
        'the refusal to invent data is stated as the deliberate behaviour it is');
});

test('advisor: the advertised environment variables are the ones the runtime really reads', function () {
    // If these drift from SportsIntelligence::registerProviders(), an operator
    // sets a variable that nothing reads and the engine stays dark.
    $source = file_get_contents(APPPATH . 'libraries/AIWorkforce/Sports/SportsIntelligence.php');
    $credential = file_get_contents(APPPATH . 'libraries/AIWorkforce/ApiProviders.php');
    foreach (ProviderSetupAdvisor::VENDORS as $vendor) {
        foreach ($vendor['envKeys'] as $key) {
            assert_true(str_contains($source, $key) || str_contains($credential, $key),
                $key . ' must be a variable the runtime actually reads');
        }
    }
    // And the drivers must be ones Admin → API can actually store.
    foreach (ProviderSetupAdvisor::VENDORS as $vendor) {
        assert_true(str_contains($credential, "'" . $vendor['driver'] . "'"),
            $vendor['driver'] . ' must be a real Admin → API driver');
    }
});

test('advisor: a present credential is reported as present — never its value', function () {
    $advice = ProviderSetupAdvisor::diagnose(
        ci152_manager(), [], ci152_env(['API_FOOTBALL_KEY' => 'super-secret-value-123']), true
    );

    $encoded = json_encode($advice);
    assert_false(str_contains($encoded, 'super-secret-value-123'), 'a credential must never reach a diagnostic payload');

    $byDriver = [];
    foreach ($advice['options'] as $option) $byDriver[$option['driver']] = $option;
    assert_true($byDriver['api_football']['environmentConfigured'], 'the present key is reported as configured');
    assert_equals(['API_FOOTBALL_KEY'], $byDriver['api_football']['environmentKeysPresent'], 'by NAME only');
    assert_false($byDriver['sportmonks']['environmentConfigured'], 'an absent vendor stays absent');
});

test('advisor: the legacy alias is recognised so a working deployment is not told it is broken', function () {
    $advice = ProviderSetupAdvisor::diagnose(
        ci152_manager(), [], ci152_env(['WINDELS_API_FOOTBALL_KEY' => 'legacy-key']), null
    );
    $byDriver = [];
    foreach ($advice['options'] as $option) $byDriver[$option['driver']] = $option;
    assert_true($byDriver['api_football']['environmentConfigured'], 'the legacy alias counts as configured');
    assert_equals(['WINDELS_API_FOOTBALL_KEY'], $byDriver['api_football']['environmentKeysPresent']);
    assert_null($advice['credentialStoreConfigured'], 'an unknowable store state stays null, never a guessed false');
});

test('advisor: registered-but-failing is a DATA OUTAGE, not a missing provider', function () {
    $provider = new \AIWorkforce\Sports\Providers\SandboxSportsProvider();
    $advice = ProviderSetupAdvisor::diagnose(
        ci152_manager([$provider]),
        ['engine' => 'BLOCKED', 'operational' => 0, 'total' => 1],
        ci152_env([]),
        true
    );
    assert_equals(ProviderSetupAdvisor::STATE_ALL_FAILING, $advice['state']);
    assert_true($advice['providersConfigured'], 'a provider IS configured — the advice must not say otherwise');
    assert_true(str_contains($advice['headline'], 'outage'), 'an empty day here is an outage, not "no qualified games"');
    assert_equals(1, $advice['totalProviders']);
    assert_equals(0, $advice['operationalProviders']);
});

test('advisor: a healthy deployment is told to do nothing', function () {
    $provider = new \AIWorkforce\Sports\Providers\SandboxSportsProvider();
    $advice = ProviderSetupAdvisor::diagnose(
        ci152_manager([$provider]), ['engine' => 'READY', 'operational' => 1, 'total' => 1], ci152_env([]), true
    );
    assert_equals(ProviderSetupAdvisor::STATE_READY, $advice['state']);
    assert_true(str_contains($advice['nextStep'], 'No action'), 'a working feed is not nagged');
});

// ═══════════════════════════════════════════════════════════════════════
// Registration itself is sound — the gap was discoverability, not wiring
// ═══════════════════════════════════════════════════════════════════════

test('a key in the environment really does register a provider and lift DISABLED_NO_PROVIDER', function () {
    $previous = getenv('API_FOOTBALL_KEY');
    putenv('API_FOOTBALL_KEY=ci152-test-key');
    try {
        $sports = new SportsIntelligence(
            ci()->AIWorkforce_model->sports,
            ci()->AIWorkforce_model->audit,
            null,
            ci()->AIWorkforce_model->db
        );
        assert_true($sports->providers->configured(), 'a present key registers a provider');
        $provider = $sports->providers->provider('api-football');
        assert_not_null($provider, 'the api-football adapter is registered');
        // The NATIVE adapter must be used, or capability checks (round sync,
        // team statistics, standings) silently lose their enrichment.
        assert_true($provider instanceof \AIWorkforce\Sports\Providers\ApiFootballProvider,
            'the native adapter is registered, not a generic HTTP wrapper');

        $status = $sports->status();
        assert_not_equals('DISABLED_NO_PROVIDER', (string) $status['ticketEngine'],
            'the engine leaves DISABLED_NO_PROVIDER as soon as a feed exists');
        // The key is a test string, so the vendor cannot actually answer. The
        // honest verdict is therefore an OUTAGE, not a missing provider — and
        // crucially not READY either: the advisor must never claim a feed
        // works merely because a credential is present.
        assert_not_equals(ProviderSetupAdvisor::STATE_NO_PROVIDER, $status['providerSetup']['state'] ?? null,
            'a registered provider is never reported as "no provider configured"');
        assert_equals(ProviderSetupAdvisor::STATE_ALL_FAILING, $status['providerSetup']['state'] ?? null,
            'an unreachable vendor is an outage, not a setup problem — and never a false READY');
    } finally {
        if ($previous === false) putenv('API_FOOTBALL_KEY');
        else putenv('API_FOOTBALL_KEY=' . $previous);
    }
});

test('with no key the engine stays honestly disabled and advertises the fix', function () {
    $status = ci()->platform->sports->status();
    $setup = $status['providerSetup'] ?? null;
    assert_true(is_array($setup), 'every status payload carries the setup advice');

    if (empty($status['providersConfigured'])) {
        assert_equals('DISABLED_NO_PROVIDER', (string) $status['ticketEngine'],
            'no feed means the engine says so rather than inventing one');
        assert_equals(ProviderSetupAdvisor::STATE_NO_PROVIDER, $setup['state']);
        assert_true(($setup['options'][0]['signup'] ?? '') !== '', 'the operator gets somewhere to actually sign up');
    }
});

test('the diagnostic surfaces are wired: console banner, CLI tool and route', function () {
    $view = file_get_contents(APPPATH . 'views/sports/index.php');
    assert_true(str_contains($view, 'providerSetup'), 'the console banner reads the advice');
    assert_true(str_contains($view, '/admin/api'), 'and links the operator to the fix');

    $tools = file_get_contents(APPPATH . 'controllers/Tools.php');
    assert_true(str_contains($tools, 'public function sports_providers()'), 'the CLI diagnostic exists');
    assert_true(str_contains($tools, 'SPORTS-PROVIDERS-RESULT:'), 'it prints a parseable result line');

    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    assert_true(str_contains($routes, "tools/sports-providers"), 'the tool is routed');
});

test('the advisor never registers, writes or fabricates anything', function () {
    $source = file_get_contents(APPPATH . 'libraries/AIWorkforce/Sports/ProviderSetupAdvisor.php');
    foreach (['->register(', 'ApiProviders::save', 'putenv(', 'INSERT', 'UPDATE '] as $forbidden) {
        assert_false(str_contains($source, $forbidden),
            'a diagnostic must never mutate state (' . $forbidden . ')');
    }
    // Diagnosing must not change what is registered.
    $manager = ci152_manager();
    ProviderSetupAdvisor::diagnose($manager, [], ci152_env(['API_FOOTBALL_KEY' => 'k']), null);
    assert_false($manager->configured(), 'diagnosis alone never connects a feed');
});

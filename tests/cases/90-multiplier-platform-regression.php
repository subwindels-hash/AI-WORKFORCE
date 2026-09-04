<?php
/**
 * Regression guards for the Multiplier Intelligence platform wiring.
 *
 * Catches the classes of breakage that previously shipped on main:
 *   1. domain files whose glob (alphabetical) load order puts an
 *      implementor before its interface/abstract base in
 *      libraries/AIWorkforce/autoload.php;
 *   2. interface methods missing from a CrashGameProvider implementation;
 *   3. a schema module that exists as SQL but is not registered in
 *      SchemaInstaller (tables never created → fatal on /multiplier);
 *   4. AgentPlatform::status() consumers expect availableAgents to be a LIST.
 */
use AIWorkforce\MultiplierIntelligence\AviatorProvider;
use AIWorkforce\MultiplierIntelligence\CrashGameProviderInterface;
use AIWorkforce\MultiplierIntelligence\CrashProviderFactory;
use AIWorkforce\MultiplierIntelligence\LiveCrashProvider;
use AIWorkforce\MultiplierIntelligence\SimulationProvider;
use AIWorkforce\SchemaInstaller;

test('domain autoload loads interface before its implementors', function () {
    // The whole suite reaching this point already proves the explicit
    // require order in autoload.php did not fatal on AviatorProvider.
    assert_true(interface_exists(CrashGameProviderInterface::class), 'CrashGameProviderInterface is loaded');
    assert_true(class_exists(AviatorProvider::class), 'AviatorProvider is loaded');
    assert_true(class_exists(SimulationProvider::class), 'SimulationProvider is loaded');
});

test('aviator provider satisfies the crash-game provider interface', function () {
    $p = new AviatorProvider();
    $ref = new ReflectionClass($p);
    assert_true($ref->implementsInterface(CrashGameProviderInterface::class), 'implements CrashGameProviderInterface');
    foreach (['isConfigured', 'currentMultiplier', 'metadata'] as $method) {
        assert_true($ref->hasMethod($method), "implements {$method}()");
    }
    assert_true(is_bool($p->isConfigured()), 'isConfigured returns bool');
    assert_true($p->isConfigured(), 'demo provider is usable');
    assert_true($p->currentMultiplier() === null, 'no live multiplier outside a round');
    $meta = $p->metadata();
    assert_equals('aviator', $meta['game']);
    assert_true(is_float($meta['houseEdge']) && $meta['houseEdge'] > 0 && $meta['houseEdge'] < 1, 'house edge is sane');
});

test('multiplier intelligence schema module is registered and installed', function () {
    assert_true(in_array('multiplier_intelligence', SchemaInstaller::MODULES, true), 'module in SchemaInstaller::MODULES');
    foreach (['sqlite' => true, 'mysql' => true] as $ext => $_) {
        $file = dirname(APPPATH) . '/application/database/multiplier_intelligence.' . $ext . '.sql';
        assert_true(is_file($file), "multiplier_intelligence.{$ext}.sql exists");
    }
    $db = platform()->model->db;
    foreach ([
        'crash_game_providers', 'crash_game_provider_health', 'crash_game_rounds',
        'crash_game_models', 'crash_game_predictions', 'crash_game_agent_executions',
        'crash_game_accuracy_snapshots', 'crash_game_active_signals',
    ] as $table) {
        assert_true(in_array($table, SchemaInstaller::EXPECTED_TABLES, true), "{$table} in EXPECTED_TABLES");
        assert_true($db->table_exists($table), "{$table} exists in the database");
    }
});

test('agent platform status exposes availableAgents as a list of roles', function () {
    $status = platform()->cloudflare->status();
    assert_true(is_array($status['communicationBus']['availableAgents'] ?? null), 'availableAgents is an array');
    foreach ($status['communicationBus']['availableAgents'] as $role) {
        assert_true(is_string($role) && $role !== '', 'agent role is a non-empty string');
    }
});

test('multiplier engine reports accuracy without validated predictions', function () {
    $engine = new AIWorkforce\MultiplierIntelligence\MultiplierIntelligenceEngine(
        new SimulationProvider(),
        platform()->model->db
    );
    $stats = $engine->accuracyStats(10);
    assert_true(is_array($stats), 'accuracyStats returns an array');
    // Empty database → unavailable, never a fatal result_array() on false.
    assert_equals(false, $stats['available'] ?? null);
});

test('live crash provider parses real Bustabit-shaped JSON and never invents rounds', function () {
    $payload = [
        'data' => [
            'games' => [
                ['id' => 101, 'bust' => 1.42, 'hash' => 'abc', 'createdAt' => '2026-01-01T00:00:00Z'],
                ['id' => 102, 'bust' => 3.10, 'hash' => 'def', 'createdAt' => '2026-01-01T00:00:10Z'],
                ['id' => 103, 'bust' => null, 'inProgress' => true, 'currentMultiplier' => 1.87],
            ],
        ],
    ];
    $parsed = LiveCrashProvider::parsePayload($payload);
    assert_equals(2, count($parsed['rounds']));
    assert_equals(1.42, $parsed['rounds'][0]['multiplier']);
    assert_equals('101', $parsed['rounds'][0]['roundId']);
    assert_true($parsed['live']['inRound'] ?? false);
    assert_equals(1.87, $parsed['live']['currentMultiplier']);

    $empty = LiveCrashProvider::parsePayload(['data' => ['games' => []]]);
    assert_equals(0, count($empty['rounds']));
    assert_true($empty['live'] === null);
});

test('crash factory defaults to live provider, not simulation', function () {
    $p = CrashProviderFactory::make();
    assert_true($p instanceof LiveCrashProvider, 'default is LiveCrashProvider');
    $sim = CrashProviderFactory::make(['code' => 'simulation']);
    assert_true($sim instanceof SimulationProvider, 'simulation only when requested');
});

test('engine returns NO_DATA instead of fabricating demo multipliers', function () {
    $transport = static function () {
        return json_encode(['data' => ['games' => []]]);
    };
    $provider = new LiveCrashProvider(['disable_cache' => true], $transport);
    $engine = new AIWorkforce\MultiplierIntelligence\MultiplierIntelligenceEngine($provider);
    $signal = $engine->generateSignal();
    assert_equals('NO_DATA', $signal['status'] ?? null);
    assert_true($signal['predictedMultiplier'] === null, 'no invented prediction');
});

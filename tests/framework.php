<?php
/**
 * AEGIS micro test framework — zero dependencies, runs through CodeIgniter's
 * CLI so every test exercises the real stack (CI3 + database + domain).
 */
if (!defined('TESTSPATH')) {
    echo "TESTSPATH not defined\n";
    return;
}

$GLOBALS['__aegis_tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__aegis_tests'][] = ['name' => $name, 'fn' => $fn];
}

/** @var CI_Controller $CI — set by the caller (Tools::tests) */
function ci(): CI_Controller
{
    return get_instance();
}

function platform(): \Aegis\Platform
{
    return ci()->platform;
}

function assert_true(bool $cond, string $msg = 'expected true'): void
{
    if (!$cond) throw new RuntimeException('ASSERT: ' . $msg);
}

function assert_false(bool $cond, string $msg = 'expected false'): void
{
    if ($cond) throw new RuntimeException('ASSERT: ' . $msg);
}

function assert_equals($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('ASSERT: ' . ($msg ?: 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true)));
    }
}

function assert_close(float $expected, float $actual, float $tol, string $msg = ''): void
{
    if (abs($expected - $actual) > $tol) {
        throw new RuntimeException(sprintf('ASSERT: %s expected %.8f got %.8f (tol %.8f)', $msg, $expected, $actual, $tol));
    }
}

function assert_throws(string $class, callable $fn, string $msg = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) return;
        throw new RuntimeException('ASSERT: ' . ($msg ?: "expected {$class} got " . get_class($e) . ': ' . $e->getMessage()));
    }
    throw new RuntimeException('ASSERT: ' . ($msg ?: "expected {$class} to be thrown, nothing thrown"));
}

function assert_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException('ASSERT: ' . ($msg ?: "expected \"{$needle}\" in \"{$haystack}\""));
    }
}

function assert_in_array($needle, array $haystack, string $msg = ''): void
{
    if (!in_array($needle, $haystack, true)) {
        throw new RuntimeException('ASSERT: ' . ($msg ?: 'expected ' . var_export($needle, true) . ' in [' . implode(',', $haystack) . ']'));
    }
}

function assert_not_null($value, string $msg = 'expected non-null'): void
{
    if ($value === null) throw new RuntimeException('ASSERT: ' . $msg);
}

function run_all_tests(): int
{
    $tests = $GLOBALS['__aegis_tests'];
    $pass = 0; $fail = 0;
    echo "\nAEGIS test suite — " . count($tests) . " tests\n" . str_repeat('=', 60) . "\n";
    $start = microtime(true);
    foreach ($tests as $t) {
        $t0 = microtime(true);
        try {
            ($t['fn'])();
            $pass++;
            printf("[ OK ] %-58s %5.0fms\n", mb_substr($t['name'], 0, 58), (microtime(true) - $t0) * 1000);
        } catch (Throwable $e) {
            $fail++;
            printf("[FAIL] %-58s %5.0fms\n       → %s\n       → %s:%d\n", mb_substr($t['name'], 0, 58), (microtime(true) - $t0) * 1000, $e->getMessage(), $e->getFile(), $e->getLine());
        }
    }
    printf(str_repeat('=', 60) . "\n%d passed, %d failed in %.1fs\n", $pass, $fail, microtime(true) - $start);
    return $fail;
}

// ---- shared fixtures -------------------------------------------------------

function fx_candles(int $n, float $drift = 0.0, int $seed = 42, float $noise = 0.4): array
{
    $rand = \Aegis\MathUtils::seededRandom($seed);
    $out = [];
    $price = 100.0;
    $now = 1755000000000;
    $h = 3600000;
    for ($i = 0; $i < $n; $i++) {
        $open = $price;
        $close = $open + $drift + ($rand() - 0.5) * $noise;
        $out[] = [
            'timestamp' => $now - ($n - $i) * $h,
            'open' => $open,
            'high' => max($open, $close) + $rand() * 0.2,
            'low' => min($open, $close) - $rand() * 0.2,
            'close' => $close,
            'volume' => 100 + $rand() * 50,
        ];
        $price = $close;
    }
    return $out;
}

function fx_noise_range(int $n, int $seed = 7, float $amp = 0.8): array
{
    $rand = \Aegis\MathUtils::seededRandom($seed);
    $out = [];
    $price = 100.0;
    $now = 1755000000000;
    $h = 3600000;
    for ($i = 0; $i < $n; $i++) {
        $open = $price;
        $close = $open + (100.0 - $open) * 0.15 + ($rand() - 0.5) * $amp; // mean-reverting: keeps ADX low
        $out[] = [
            'timestamp' => $now - ($n - $i) * $h,
            'open' => $open,
            'high' => max($open, $close) + $rand() * 0.1,
            'low' => min($open, $close) - $rand() * 0.1,
            'close' => $close,
            'volume' => 100,
        ];
        $price = $close;
    }
    return $out;
}

function fx_series(array $candles, string $symbol = 'TESTUSD', string $marketClass = 'crypto', bool $synthetic = true): array
{
    return [
        'symbol' => $symbol, 'marketClass' => $marketClass, 'timeframe' => '1h',
        'candles' => $candles,
        'provenance' => [
            'source' => $synthetic ? 'synthetic-demo' : 'test', 'synthetic' => $synthetic,
            'live' => !$synthetic, 'delayed' => false, 'fetchedAt' => 1755000000000,
            'dataTimestamp' => $candles ? end($candles)['timestamp'] : 0, 'dataAgeMs' => 0,
            'stale' => false, 'fallbackChain' => [],
        ],
        'validation' => ['ok' => true, 'droppedCount' => 0, 'gapCount' => 0, 'expectedIntervalMs' => 3600000,
            'coveredIntervalMs' => 0, 'minTimestamp' => 0, 'maxTimestamp' => 0, 'issues' => []],
    ];
}

function fx_ctx(array $series, int $now = 1755000000000): array
{
    return ['series' => $series, 'now' => $now, 'referenceSeries' => []];
}

/** clean risk context */
function fx_risk_ctx(array $over = []): array
{
    return array_merge([
        'killSwitchActive' => false, 'dataQuality' => 0.9, 'syntheticData' => false, 'staleData' => false,
        'equity' => 10000, 'openRiskBySymbol' => [], 'openPositions' => 0, 'dailyPnl' => 0, 'weeklyPnl' => 0, 'peakEquity' => 10000,
    ], $over);
}

function fx_setup(array $over = []): array
{
    return array_merge([
        'action' => 'BUY', 'symbol' => 'EURUSD',
        'entry' => ['type' => 'ZONE', 'min' => 1.0810, 'max' => 1.0820, 'reference' => 1.0815],
        'stopLoss' => 1.0785, 'takeProfit' => [1.0855], 'riskReward' => 2.0,
    ], $over);
}

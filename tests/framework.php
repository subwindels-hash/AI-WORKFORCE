<?php
/**
 * AI Workforce micro test framework — zero dependencies, runs through CodeIgniter's
 * CLI so every test exercises the real stack (CI3 + database + domain).
 */
if (!defined('TESTSPATH')) {
    echo "TESTSPATH not defined\n";
    return;
}

$GLOBALS['__ai_workforce_tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__ai_workforce_tests'][] = ['name' => $name, 'fn' => $fn];
}

/** @var CI_Controller $CI — set by the caller (Tools::tests) */
function ci(): CI_Controller
{
    return get_instance();
}

function platform(): \AIWorkforce\Platform
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

function assert_not_equals($unexpected, $actual, string $msg = ''): void
{
    if ($unexpected === $actual) {
        throw new RuntimeException('ASSERT: ' . ($msg ?: 'value must not be ' . var_export($actual, true)));
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

function assert_not_contains(string $needle, string $haystack, string $msg = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException('ASSERT: ' . ($msg ?: "did not expect \"{$needle}\" in output"));
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

function assert_null($value, string $msg = 'expected null'): void
{
    if ($value !== null) throw new RuntimeException('ASSERT: ' . $msg . ' (got ' . var_export($value, true) . ')');
}

function run_all_tests(): int
{
    $tests = $GLOBALS['__ai_workforce_tests'];
    $pass = 0; $fail = 0;
    echo "\nAI Workforce test suite — " . count($tests) . " tests\n" . str_repeat('=', 60) . "\n";
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
    $rand = \AIWorkforce\MathUtils::seededRandom($seed);
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
    $rand = \AIWorkforce\MathUtils::seededRandom($seed);
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

/**
 * In-memory SportsRepository stub for tests (implements the full interface
 * without CI3). Used by sports engine tests that don't need SQL.
 */
class SportsRepositoryStub implements \AIWorkforce\Persistence\SportsRepository
{
    public array $providers = [];
    public array $health = [];
    public array $matches = [];
    public array $odds = [];
    public array $results = [];
    public array $quality = [];
    public array $syncKeys = [];
    public array $syncRuns = [];
    public array $modelVersions = [];
    public array $predictions = [];
    public array $tickets = [];
    public array $ticketSelections = [];
    public array $configurations = [];
    public array $calibrations = [];
    public array $jobRuns = [];
    public array $jobKeys = [];
    public array $backtests = [];
    public array $modelMetrics = [];
    public array $dailyTickets = [];
    public array $perfSnapshots = [];

    private int $autoId = 0;

    public function ensureProvider(string $code, string $name): array
    {
        foreach ($this->providers as $p) if ($p['provider_code'] === $code) return $p;
        $row = ['id' => ++$this->autoId, 'provider_code' => $code, 'display_name' => $name, 'enabled' => 0, 'created_at' => gmdate('c'), 'updated_at' => gmdate('c')];
        $this->providers[] = $row;
        return $row;
    }
    public function listProviders(bool $enabledOnly = false): array { return $enabledOnly ? array_values(array_filter($this->providers, fn($p) => $p['enabled'])) : $this->providers; }
    public function setProviderEnabled(int $id, bool $enabled): void { foreach ($this->providers as &$p) if ($p['id'] === $id) { $p['enabled'] = $enabled ? 1 : 0; $p['updated_at'] = gmdate('c'); } }
    public function listHealth(int $providerId, int $limit = 20): array { return array_slice(array_values(array_filter($this->health, fn($h) => $h['provider_id'] === $providerId)), -$limit); }
    public function latestHealth(int $providerId): ?array { $rows = $this->listHealth($providerId, 1); return $rows ? end($rows) : null; }
    public function saveHealth(int $providerId, array $health): void { $this->health[] = array_merge($health, ['provider_id' => $providerId, 'observed_at' => gmdate('c')]); }
    public function findMatchById(int $id): ?array { foreach ($this->matches as $m) if ($m['id'] === $id) return $m; return null; }
    public function listMatches(array $filter = [], int $limit = 200): array
    {
        $rows = $this->matches;
        if (!empty($filter['status'])) $rows = array_values(array_filter($rows, fn($m) => ($m['status'] ?? '') === $filter['status']));
        if (!empty($filter['from'])) $rows = array_values(array_filter($rows, fn($m) => ($m['kickoff_at'] ?? '') >= $filter['from']));
        if (!empty($filter['to'])) $rows = array_values(array_filter($rows, fn($m) => ($m['kickoff_at'] ?? '') <= $filter['to']));
        if (!empty($filter['competition'])) $rows = array_values(array_filter($rows, fn($m) => str_contains((string) ($m['competition'] ?? ''), (string) $filter['competition'])));
        if (!empty($filter['providerId'])) $rows = array_values(array_filter($rows, fn($m) => (int) ($m['provider_id'] ?? 0) === (int) $filter['providerId']));
        return array_slice($rows, 0, $limit);
    }
    public function saveMatch(int $providerId, array $m): array
    {
        foreach ($this->matches as &$row) {
            if ((int) $row['provider_id'] === $providerId && $row['external_id'] === $m['externalId']) {
                $row = array_merge($row, ['sport' => $m['sport'], 'competition' => $m['competition'], 'home_team' => $m['homeTeam'], 'away_team' => $m['awayTeam'], 'kickoff_at' => $m['kickoff'], 'status' => $m['status'], 'source_timestamp' => $m['sourceTimestamp'], 'round_id' => (string) ($m['roundId'] ?? '') !== '' ? (string) $m['roundId'] : null, 'payload' => $m, 'updated_at' => gmdate('c')]);
                return $row;
            }
        }
        $row = ['id' => ++$this->autoId, 'provider_id' => $providerId, 'external_id' => $m['externalId'], 'sport' => $m['sport'], 'competition' => $m['competition'], 'home_team' => $m['homeTeam'], 'away_team' => $m['awayTeam'], 'kickoff_at' => $m['kickoff'], 'status' => $m['status'], 'source_timestamp' => $m['sourceTimestamp'], 'round_id' => (string) ($m['roundId'] ?? '') !== '' ? (string) $m['roundId'] : null, 'payload' => $m, 'created_at' => gmdate('c'), 'updated_at' => gmdate('c')];
        $this->matches[] = $row;
        return $row;
    }
    public function findMatch(int $providerId, string $externalId): ?array { foreach ($this->matches as $m) if ((int) $m['provider_id'] === $providerId && $m['external_id'] === $externalId) return $m; return null; }
    public function saveOdds(int $matchId, int $providerId, array $odds): void { $this->odds[] = ['id' => ++$this->autoId, 'match_id' => $matchId, 'provider_id' => $providerId, 'market' => $odds['market'], 'selection' => $odds['selection'], 'decimal_odds' => $odds['decimalOdds'], 'observed_at' => $odds['observedAt'], 'payload' => $odds]; }
    public function latestOdds(int $matchId, ?string $market = null, ?string $selection = null): ?array
    {
        $rows = array_values(array_filter($this->odds, fn($o) => (int) $o['match_id'] === $matchId && ($market === null || $o['market'] === $market) && ($selection === null || $o['selection'] === $selection)));
        usort($rows, fn($a, $b) => strcmp($b['observed_at'], $a['observed_at']));
        return $rows ? $rows[0] : null;
    }
    public function listOdds(int $matchId, int $limit = 50): array { $rows = array_values(array_filter($this->odds, fn($o) => (int) $o['match_id'] === $matchId)); usort($rows, fn($a, $b) => strcmp($b['observed_at'], $a['observed_at'])); return array_slice($rows, 0, $limit); }
    public function latestQuality(int $matchId): ?array { $rows = array_values(array_filter($this->quality, fn($q) => (int) $q['match_id'] === $matchId)); return $rows ? end($rows) : null; }
    public function saveQuality(int $matchId, array $a): void { $this->quality[] = array_merge($a, ['match_id' => $matchId, 'assessed_at' => gmdate('c')]); }
    public function saveResult(int $matchId, int $providerId, array $r): void
    {
        foreach ($this->results as &$row) if ((int) $row['match_id'] === $matchId && (int) $row['provider_id'] === $providerId) { $row = array_merge($row, ['home_score' => $r['homeScore'], 'away_score' => $r['awayScore'], 'status' => $r['status'], 'verified' => 0, 'source_timestamp' => $r['sourceTimestamp'], 'verified_at' => null, 'payload' => $r['payload']]); return; }
        $this->results[] = ['id' => ++$this->autoId, 'match_id' => $matchId, 'provider_id' => $providerId, 'home_score' => $r['homeScore'], 'away_score' => $r['awayScore'], 'status' => $r['status'], 'verified' => 0, 'source_timestamp' => $r['sourceTimestamp'], 'verified_at' => null, 'payload' => $r['payload']];
    }
    public function findResult(int $matchId, int $providerId): ?array { foreach ($this->results as $r) if ((int) $r['match_id'] === $matchId && (int) $r['provider_id'] === $providerId) return $r; return null; }
    public function findResultByMatch(int $matchId): ?array { $rows = array_values(array_filter($this->results, fn($r) => (int) $r['match_id'] === $matchId)); if (!$rows) return null; usort($rows, fn($a, $b) => ($b['verified'] ?? 0) <=> ($a['verified'] ?? 0)); return $rows[0]; }
    public function verifyResult(int $id): void { foreach ($this->results as &$r) if ((int) $r['id'] === $id) { $r['verified'] = 1; $r['verified_at'] = gmdate('c'); } }
    public function startSync(array $run): ?array { if (isset($this->syncKeys[$run['executionKey']])) return null; $this->syncKeys[$run['executionKey']] = true; $this->syncRuns[] = array_merge($run, ['status' => 'RUNNING', 'started_at' => gmdate('c')]); return $run; }
    public function finishSync(string $id, array $result): void { foreach ($this->syncRuns as &$r) if ($r['id'] === $id) $r = array_merge($r, ['status' => $result['status'], 'ended_at' => gmdate('c'), 'records_processed' => $result['processed'] ?? 0, 'records_created' => $result['created'] ?? 0, 'records_updated' => $result['updated'] ?? 0, 'errors' => $result['errors'] ?? [], 'result' => $result]); }
    public function listSyncRuns(?string $jobType = null, int $limit = 50): array
    {
        $rows = $jobType === null ? $this->syncRuns : array_values(array_filter($this->syncRuns, fn($r) => ($r['jobType'] ?? $r['job_type'] ?? '') === $jobType));
        $rows = array_reverse($rows);
        $map = fn($r) => array_merge($r, ['job_type' => $r['job_type'] ?? $r['jobType'] ?? '', 'provider_id' => $r['provider_id'] ?? $r['providerId'] ?? null, 'execution_key' => $r['execution_key'] ?? $r['executionKey'] ?? '', 'errors' => $r['errors'] ?? $r['result']['errors'] ?? []]);
        return array_slice(array_map($map, $rows), 0, max(1, $limit));
    }
    public function ensureModelVersion(array $m): int
    {
        foreach ($this->modelVersions as $v) if ($v['model_name'] === $m['modelName'] && $v['model_version'] === $m['modelVersion']) return (int) $v['id'];
        $row = ['id' => ++$this->autoId, 'model_name' => $m['modelName'], 'model_version' => $m['modelVersion'], 'feature_version' => $m['featureVersion'], 'calibration_version' => $m['calibrationVersion'] ?? null, 'status' => $m['status'] ?? 'APPROVED', 'created_at' => gmdate('c')];
        $this->modelVersions[] = $row;
        return (int) $row['id'];
    }
    public function listModelVersions(): array { return $this->modelVersions; }
    public function findModelVersion(int $id): ?array { foreach ($this->modelVersions as $v) if ((int) $v['id'] === $id) return $v; return null; }
    public function savePrediction(array $p): void { $this->predictions[] = $p; }
    public function listPredictions(array $filter = [], int $limit = 200): array
    {
        $rows = $this->predictions;
        if (!empty($filter['matchId'])) $rows = array_values(array_filter($rows, fn($p) => (int) $p['match_id'] === (int) $filter['matchId']));
        if (!empty($filter['modelVersionId'])) $rows = array_values(array_filter($rows, fn($p) => (int) $p['model_version_id'] === (int) $filter['modelVersionId']));
        if (!empty($filter['decision'])) $rows = array_values(array_filter($rows, fn($p) => ($p['decision'] ?? '') === $filter['decision']));
        if (!empty($filter['market'])) $rows = array_values(array_filter($rows, fn($p) => ($p['market'] ?? '') === $filter['market']));
        if (!empty($filter['from'])) $rows = array_values(array_filter($rows, fn($p) => ($p['created_at'] ?? '') >= $filter['from']));
        if (!empty($filter['to'])) $rows = array_values(array_filter($rows, fn($p) => ($p['created_at'] ?? '') <= $filter['to']));
        $this->decodePredictionJson($rows);
        return array_slice($rows, 0, $limit);
    }
    /** Mirrors the real repository: factors/rejection_reasons are stored as JSON. */
    private function decodePredictionJson(array &$rows): void
    {
        foreach ($rows as &$row) {
            if (isset($row['factors']) && is_string($row['factors'])) $row['factors'] = json_decode($row['factors'], true) ?? [];
            if (isset($row['rejection_reasons']) && is_string($row['rejection_reasons'])) $row['rejection_reasons'] = json_decode($row['rejection_reasons'], true) ?? [];
        }
        unset($row);
    }
    public function findPrediction(string $id): ?array { foreach ($this->predictions as $p) if ($p['id'] === $id) { $rows = [$p]; $this->decodePredictionJson($rows); return $rows[0]; } return null; }
    public function predictionOutcomes(?int $modelVersionId = null): array
    {
        $out = [];
        foreach ($this->predictions as $p) {
            if ($modelVersionId !== null && (int) $p['model_version_id'] !== $modelVersionId) continue;
            $match = $this->findMatchById((int) $p['match_id']);
            $result = $this->findResultByMatch((int) $p['match_id']);
            if ($match === null || $result === null || !(bool) $result['verified'] || ($result['status'] ?? '') !== 'FINISHED') continue;
            $total = (int) $result['home_score'] + (int) $result['away_score'];
            if ($p['market'] === 'TOTAL_GOALS' && $p['selection'] === 'OVER_1_5') $outcome = $total > 1 ? 1 : 0;
            elseif ($p['market'] === 'TOTAL_GOALS' && $p['selection'] === 'UNDER_1_5') $outcome = $total <= 1 ? 1 : 0;
            else continue;
            $out[] = array_merge($p, ['outcome' => $outcome, 'competition' => $match['competition'] ?? null, 'home_score' => $result['home_score'], 'away_score' => $result['away_score']]);
        }
        return $out;
    }
    public function activeConfiguration(): ?array
    {
        if (!$this->configurations) return null;
        usort($this->configurations, fn($a, $b) => (int) $b['version'] <=> (int) $a['version']);
        return $this->decodeConfig($this->configurations[0]);
    }
    public function listConfigurations(int $limit = 20): array { usort($this->configurations, fn($a, $b) => (int) $b['version'] <=> (int) $a['version']); return array_map(fn($c) => $this->decodeConfig($c), array_slice($this->configurations, 0, $limit)); }
    public function saveConfiguration(array $c): int { $row = array_merge(['id' => ++$this->autoId, 'created_at' => gmdate('c')], $c); $this->configurations[] = $row; return (int) $row['id']; }
    public function findConfiguration(int $id): ?array { foreach ($this->configurations as $c) if ((int) $c['id'] === $id) return $this->decodeConfig($c); return null; }
    private function decodeConfig(array $c): array { $c['allowed_markets'] = json_decode((string) ($c['allowed_markets'] ?? '[]'), true) ?: []; $c['allowed_leagues'] = json_decode((string) ($c['allowed_leagues'] ?? '[]'), true) ?: []; return $c; }
    public function saveCalibration(array $c): int { $row = array_merge(['id' => ++$this->autoId, 'created_at' => gmdate('c'), 'status' => 'PENDING'], $c); $this->calibrations[] = $row; return (int) $row['id']; }
    public function findCalibration(int $id): ?array { foreach ($this->calibrations as $c) if ((int) $c['id'] === $id) return $c; return null; }
    public function listCalibrations(?int $modelVersionId = null, ?string $status = null, int $limit = 50): array { $rows = array_values(array_filter($this->calibrations, fn($c) => ($modelVersionId === null || (int) $c['model_version_id'] === $modelVersionId) && ($status === null || ($c['status'] ?? '') === $status))); return array_slice($rows, -$limit); }
    public function activeCalibration(int $modelVersionId): ?array
    {
        $rows = array_values(array_filter($this->calibrations, fn($c) => (int) $c['model_version_id'] === $modelVersionId && ($c['status'] ?? '') === 'APPROVED'));
        if (!$rows) return null;
        usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $rows[0];
    }
    public function updateCalibrationStatus(int $id, string $status, ?string $actor = null): void { foreach ($this->calibrations as &$c) if ((int) $c['id'] === $id) { $c['status'] = $status; if ($actor !== null) { $c['approved_by'] = $actor; $c['approved_at'] = gmdate('c'); } } }
    public function startJobRun(array $run): ?array { if (isset($this->jobKeys[$run['executionKey']])) return null; $this->jobKeys[$run['executionKey']] = true; $this->jobRuns[] = $run; return $run; }
    public function finishJobRun(string $id, array $result): void { foreach ($this->jobRuns as &$r) if ($r['id'] === $id) $r = array_merge($r, ['status' => $result['status'], 'result' => $result]); }
    public function releaseJobRun(string $id): void { foreach ($this->jobRuns as &$r) if ($r['id'] === $id && isset($r['executionKey'])) { unset($this->jobKeys[$r['executionKey']]); $r['executionKey'] .= '#released:' . $id; } }
    public function listJobRuns(?string $jobType = null, int $limit = 50): array { $rows = $jobType === null ? $this->jobRuns : array_values(array_filter($this->jobRuns, fn($r) => ($r['job_type'] ?? '') === $jobType)); return array_slice(array_reverse($rows), 0, $limit); }
    public function saveBacktest(array $b): void { $this->backtests[] = $b; }
    public function findBacktest(string $id): ?array { foreach ($this->backtests as $b) if ($b['id'] === $id) return $b; return null; }
    public function listBacktests(int $limit = 20): array { return array_slice(array_reverse($this->backtests), 0, $limit); }
    public function saveModelMetrics(array $m): void { $this->modelMetrics[] = $m; }
    public function listModelMetrics(?int $modelVersionId = null, ?int $windowDays = null, ?string $sampleType = null, int $limit = 200): array { return array_slice(array_reverse(array_values(array_filter($this->modelMetrics, fn($m) => ($modelVersionId === null || (int) $m['model_version_id'] === $modelVersionId) && ($windowDays === null || (int) $m['window_days'] === $windowDays) && ($sampleType === null || ($m['sample_type'] ?? '') === $sampleType)))), 0, $limit); }
    public function findDailyTicket(string $date): ?array { foreach ($this->dailyTickets as $d) if ($d['date'] === $date) { $d['rejection_summary'] = is_string($d['rejection_summary'] ?? null) ? (json_decode($d['rejection_summary'], true) ?? []) : ($d['rejection_summary'] ?? []); return $d; } return null; }
    public function saveDailyTicket(array $d): void { foreach ($this->dailyTickets as &$x) if ($x['date'] === $d['date']) { $x = $d; return; } $this->dailyTickets[] = $d; }
    public function updateDailyTicket(string $date, array $patch): void { foreach ($this->dailyTickets as &$x) if ($x['date'] === $date) $x = array_merge($x, $patch, ['updated_at' => gmdate('c')]); }
    public function listDailyTickets(int $limit = 60): array { usort($this->dailyTickets, fn($a, $b) => strcmp($b['date'], $a['date'])); $rows = array_slice($this->dailyTickets, 0, $limit); foreach ($rows as &$d) if (is_string($d['rejection_summary'] ?? null)) $d['rejection_summary'] = json_decode($d['rejection_summary'], true) ?? []; unset($d); return $rows; }
    public function savePerformanceSnapshot(string $asOf, string $window, array $payload): void { foreach ($this->perfSnapshots as &$s) if ($s['as_of'] === $asOf && $s['window'] === $window) { $s['payload'] = $payload; return; } $this->perfSnapshots[] = ['as_of' => $asOf, 'window' => $window, 'payload' => $payload]; }
    public function performanceSnapshots(string $window, int $limit = 30): array { $rows = array_values(array_filter($this->perfSnapshots, fn($s) => $s['window'] === $window)); return array_slice(array_reverse($rows), 0, $limit); }
    public function settledSelections(array $filter = []): array
    {
        $out = [];
        foreach ($this->ticketSelections as $s) {
            $ticket = null;
            foreach ($this->tickets as $t) if ($t['id'] === $s['ticket_id']) { $ticket = $t; break; }
            if ($ticket === null) continue;
            $match = $this->findMatchById((int) $s['match_id']);
            $model = $this->findModelVersion((int) ($ticket['model_version_id'] ?? 0));
            $row = array_merge($s, ['ticket_odds' => $ticket['total_odds'] ?? null, 'ticket_status' => $ticket['settlement_status'] ?? null, 'ticket_stake' => $ticket['stake'] ?? null, 'ticket_created_at' => $ticket['created_at'] ?? null, 'competition' => $match['competition'] ?? null, 'kickoff_at' => $match['kickoff_at'] ?? null, 'model_name' => $model['model_name'] ?? null, 'model_version' => $model['model_version'] ?? null]);
            if (!empty($filter['from']) && ($ticket['created_at'] ?? '') < $filter['from']) continue;
            if (!empty($filter['to']) && ($ticket['created_at'] ?? '') > $filter['to']) continue;
            if (!empty($filter['market']) && ($s['market'] ?? '') !== $filter['market']) continue;
            if (!empty($filter['modelVersionId']) && (int) ($ticket['model_version_id'] ?? 0) !== (int) $filter['modelVersionId']) continue;
            $row['_settled'] = in_array($s['status'] ?? '', ['WON', 'LOST', 'VOID', 'CANCELLED'], true) ? 1 : 0;
            $out[] = $row;
        }
        return $out;
    }
    public function saveTicket(array $t): void { $this->tickets[] = $t; }
    public function findTicket(string $id): ?array { foreach ($this->tickets as $t) if ($t['id'] === $id) return $t; return null; }
    public function listTickets(array $filter = [], int $limit = 500): array
    {
        $rows = $this->tickets;
        if (!empty($filter['from'])) $rows = array_values(array_filter($rows, fn($t) => ($t['created_at'] ?? '') >= $filter['from']));
        if (!empty($filter['to'])) $rows = array_values(array_filter($rows, fn($t) => ($t['created_at'] ?? '') <= $filter['to']));
        if (!empty($filter['status'])) $rows = array_values(array_filter($rows, fn($t) => ($t['settlement_status'] ?? '') === $filter['status']));
        if (!empty($filter['modelVersionId'])) $rows = array_values(array_filter($rows, fn($t) => (int) ($t['model_version_id'] ?? 0) === (int) $filter['modelVersionId']));
        usort($rows, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        return array_slice($rows, 0, $limit);
    }
    public function updateTicket(string $id, array $patch): void { foreach ($this->tickets as &$t) if ($t['id'] === $id) $t = array_merge($t, $patch); }
    public function saveTicketSelection(array $s): void { $this->ticketSelections[] = array_merge(['id' => count($this->ticketSelections) + 1], $s); }
    public function ticketSelections(string $ticketId): array { return array_values(array_filter($this->ticketSelections, fn($s) => $s['ticket_id'] === $ticketId)); }
    public function updateTicketSelection(int $id, array $patch): void { foreach ($this->ticketSelections as &$s) if ((int) $s['id'] === $id) $s = array_merge($s, $patch); }
    public function oddsBefore(int $matchId, string $timestamp): ?array {
        $limit = strtotime($timestamp);
        if ($limit === false) return null;
        $rows = array_values(array_filter($this->odds, fn($o) => (int) $o['match_id'] === $matchId && strtotime((string) $o['observed_at']) !== false && strtotime((string) $o['observed_at']) < $limit));
        if (!$rows) return null;
        usort($rows, fn($a, $b) => strcmp($b['observed_at'], $a['observed_at']));
        return $rows[0];
    }
    public function recordTicketOutcome(string $ticketId, float $pnl): void { foreach ($this->tickets as &$t) if ($t['id'] === $ticketId) $t['pnl'] = $pnl; }
    public function deleteOldJobRuns(string $cutoff): void { $this->jobRuns = array_values(array_filter($this->jobRuns, fn($r) => ($r['started_at'] ?? '') >= $cutoff)); }
    public function deleteOldHealth(string $cutoff): void { $this->health = array_values(array_filter($this->health, fn($h) => ($h['observed_at'] ?? '') >= $cutoff)); }
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

/** In-memory LotteryRepository for unit tests (mirrors the CI3 model layer). */
class LotteryRepositoryStub implements \AIWorkforce\Persistence\LotteryRepository
{
    public array $lotteries = [];
    public array $rules = [];
    public array $providers = [];
    public array $health = [];
    public array $draws = [];
    public array $numbers = [];
    public array $jobRuns = [];

    private int $autoId = 0;

    public function ensureLottery(string $code, string $name, string $rulesVersion): array
    {
        foreach ($this->lotteries as $l) if ($l['code'] === $code) return $l;
        $row = ['id' => ++$this->autoId, 'code' => $code, 'name' => $name, 'enabled' => 1, 'rules_version' => $rulesVersion, 'created_at' => gmdate('c'), 'updated_at' => gmdate('c')];
        $this->lotteries[] = $row;
        return $row;
    }
    public function listLotteries(): array { return $this->lotteries; }
    public function activeRules(string $lotteryCode): ?array
    {
        foreach (array_reverse($this->rules) as $r) if ($r['lottery_code'] === $lotteryCode && (int) $r['active'] === 1) return $r;
        return null;
    }
    public function saveRules(array $r): int { $row = array_merge(['id' => ++$this->autoId, 'created_at' => gmdate('c')], $r); $this->rules[] = $row; return (int) $row['id']; }
    public function ensureProvider(string $code, string $name): array
    {
        foreach ($this->providers as $p) if ($p['provider_code'] === $code) return $p;
        $row = ['id' => ++$this->autoId, 'provider_code' => $code, 'display_name' => $name, 'enabled' => 0, 'synthetic' => str_contains($code, 'sandbox') ? 1 : 0, 'created_at' => gmdate('c'), 'updated_at' => gmdate('c')];
        $this->providers[] = $row;
        return $row;
    }
    public function listProviders(bool $enabledOnly = false): array { return $enabledOnly ? array_values(array_filter($this->providers, fn($p) => $p['enabled'])) : $this->providers; }
    public function saveHealth(int $providerId, array $h): void { $this->health[] = array_merge($h, ['id' => ++$this->autoId, 'provider_id' => $providerId, 'observed_at' => gmdate('c')]); }
    public function latestHealth(int $providerId): ?array { $rows = $this->listHealth($providerId, 1); return $rows ? $rows[0] : null; }
    public function listHealth(int $providerId, int $limit = 20): array
    {
        $rows = array_values(array_filter($this->health, fn($h) => (int) $h['provider_id'] === $providerId));
        return array_slice(array_reverse($rows), 0, $limit);
    }
    private function decodedDraw(array $d): array { $d['payload'] = json_decode((string) ($d['payload'] ?? ''), true); return $d; }
    public function findDraw(int $id): ?array { foreach ($this->draws as $d) if ((int) $d['id'] === $id) return $this->decodedDraw($d); return null; }
    public function findDrawByExternal(string $lotteryCode, string $externalId): ?array
    {
        foreach ($this->draws as $d) if ($d['lottery_code'] === $lotteryCode && $d['external_id'] === $externalId) return $this->decodedDraw($d);
        return null;
    }
    public function listDraws(array $filter = [], int $limit = 100, string $order = 'DESC'): array
    {
        $rows = $this->draws;
        if (!empty($filter['lotteryCode'])) $rows = array_values(array_filter($rows, fn($d) => $d['lottery_code'] === $filter['lotteryCode']));
        if (!empty($filter['from'])) $rows = array_values(array_filter($rows, fn($d) => $d['draw_date'] >= $filter['from']));
        if (!empty($filter['to'])) $rows = array_values(array_filter($rows, fn($d) => $d['draw_date'] <= $filter['to']));
        if (!empty($filter['verificationStatus'])) $rows = array_values(array_filter($rows, fn($d) => $d['verification_status'] === $filter['verificationStatus']));
        usort($rows, fn($a, $b) => $order === 'ASC' ? strcmp($a['draw_date'], $b['draw_date']) : strcmp($b['draw_date'], $a['draw_date']));
        return array_map(fn($d) => $this->decodedDraw($d), array_slice($rows, 0, $limit));
    }
    public function saveDraw(array $d): array
    {
        $existing = $this->findDrawByExternal($d['lottery_code'], $d['external_id']);
        if ($existing) {
            foreach ($this->draws as &$row) {
                if ((int) $row['id'] === (int) $existing['id']) {
                    $row = array_merge($row, $d, ['updated_at' => gmdate('c')]);
                    break;
                }
            }
            return ['row' => $this->findDraw((int) $existing['id']), 'created' => false];
        }
        $row = array_merge(['id' => ++$this->autoId], $d);
        $this->draws[] = $row;
        return ['row' => $this->findDraw((int) $row['id']), 'created' => true];
    }
    public function listDrawNumbers(int $drawId): array
    {
        $rows = array_values(array_filter($this->numbers, fn($n) => (int) $n['draw_id'] === $drawId));
        usort($rows, fn($a, $b) => strcmp($a['kind'], $b['kind']) ?: $a['position'] <=> $b['position']);
        return $rows;
    }
    public function saveDrawNumbers(int $drawId, array $numbers): void
    {
        $this->numbers = array_values(array_filter($this->numbers, fn($n) => (int) $n['draw_id'] !== $drawId));
        foreach (['main' => 'MAIN', 'stars' => 'STAR'] as $field => $kind) {
            foreach (array_values((array) ($numbers[$field] ?? [])) as $i => $n) {
                $this->numbers[] = ['id' => ++$this->autoId, 'draw_id' => $drawId, 'kind' => $kind, 'position' => $i, 'number' => (int) $n];
            }
        }
    }
    public function drawsForStats(string $lotteryCode, int $limit = 10000): array
    {
        $rows = $this->listDraws(['lotteryCode' => $lotteryCode], $limit, 'ASC');
        $out = [];
        foreach ($rows as $r) {
            $p = is_array($r['payload'] ?? null) ? $r['payload'] : [];
            if (!is_array($p['main'] ?? null) || !is_array($p['stars'] ?? null)) continue;
            $out[] = ['drawDate' => (string) $r['draw_date'], 'main' => array_map('intval', $p['main']), 'stars' => array_map('intval', $p['stars'])];
        }
        return $out;
    }
    public function countDraws(string $lotteryCode): int { return count(array_filter($this->draws, fn($d) => $d['lottery_code'] === $lotteryCode)); }
    public function startJobRun(array $run): ?array
    {
        foreach ($this->jobRuns as $r) if (($r['execution_key'] ?? null) === ($run['executionKey'] ?? null)) return null;
        // store the DB row shape (snake_case columns), not the input shape
        $row = [
            'id' => $run['id'],
            'provider_id' => $run['providerId'] ?? null,
            'job_type' => $run['jobType'],
            'status' => 'RUNNING',
            'started_at' => gmdate('c'),
            'payload' => $run['payload'] ?? null,
            'execution_key' => $run['executionKey'],
        ];
        $this->jobRuns[] = $row;
        return $run;
    }
    public function finishJobRun(string $id, array $result): void
    {
        foreach ($this->jobRuns as &$r) if ((string) $r['id'] === $id) { $r['status'] = $result['status']; $r['ended_at'] = gmdate('c'); $r['records_processed'] = $result['processed'] ?? 0; $r['records_created'] = $result['created'] ?? 0; $r['records_updated'] = $result['updated'] ?? 0; $r['errors'] = json_encode($result['errors'] ?? []); }
    }
    public function listJobRuns(?string $jobType = null, int $limit = 50): array
    {
        $rows = $jobType !== null ? array_values(array_filter($this->jobRuns, fn($r) => $r['job_type'] === $jobType)) : $this->jobRuns;
        return array_slice(array_reverse($rows), 0, $limit);
    }
    public function findJobRunByKey(string $key): ?array
    {
        foreach ($this->jobRuns as $r) if ($r['execution_key'] === $key) return $r;
        return null;
    }
    public function deleteOldJobRuns(string $cutoff): void { $this->jobRuns = array_values(array_filter($this->jobRuns, fn($r) => $r['started_at'] >= $cutoff)); }
    public function deleteOldHealth(string $cutoff): void { $this->health = array_values(array_filter($this->health, fn($h) => $h['observed_at'] >= $cutoff)); }
    public array $combinations = [];
    public array $aiDecisions = [];
    public function saveCombination(array $c): array
    {
        $row = array_merge(['id' => ++$this->autoId], $c);
        $this->combinations[] = $row;
        return ['row' => $this->findCombination((int) $row['id']), 'created' => true];
    }
    public function findCombination(int $id): ?array
    {
        foreach ($this->combinations as $c) if ((int) $c['id'] === $id) {
            $r = $c;
            $r['lines'] = json_decode((string) $r['lines'], true);
            $r['constraints'] = json_decode((string) $r['constraints'], true);
            $r['score_summary'] = json_decode((string) $r['score_summary'], true);
            return $r;
        }
        return null;
    }
    public function listCombinations(int $limit = 50, int $offset = 0): array
    {
        $rows = array_slice(array_reverse($this->combinations), max(0, $offset), min(200, max(1, $limit)));
        return array_map(fn($c) => $this->findCombination((int) $c['id']), $rows);
    }
    public function saveAiDecision(array $d): array
    {
        $row = array_merge(['id' => ++$this->autoId], $d);
        $this->aiDecisions[] = $row;
        return ['row' => $this->findAiDecision((int) $row['id']), 'created' => true];
    }
    public function findAiDecision(int $id): ?array
    {
        foreach ($this->aiDecisions as $d) if ((int) $d['id'] === $id) {
            $r = $d;
            $r['decision'] = json_decode((string) $r['decision'], true);
            return $r;
        }
        return null;
    }
    public function listAiDecisions(?int $combinationId = null, int $limit = 50): array
    {
        $rows = $combinationId !== null
            ? array_values(array_filter($this->aiDecisions, fn($d) => (int) $d['combination_id'] === $combinationId))
            : $this->aiDecisions;
        return array_map(fn($d) => $this->findAiDecision((int) $d['id']), array_slice(array_reverse($rows), 0, min(500, max(1, $limit))));
    }
    public array $tickets = [];
    public array $ticketLines = [];
    public function saveTicket(array $t): array
    {
        $row = array_merge(['id' => ++$this->autoId], $t);
        $this->tickets[] = $row;
        return ['row' => $this->findTicket((int) $row['id']), 'created' => true];
    }
    public function findTicket(int $id, ?int $userId = null): ?array
    {
        foreach ($this->tickets as $t) if ((int) $t['id'] === $id && ($userId === null || (int) $t['user_id'] === $userId)) {
            $r = $t;
            $r['configuration'] = json_decode((string) $r['configuration'], true);
            $r['result'] = $r['result'] !== null ? json_decode((string) $r['result'], true) : null;
            return $r;
        }
        return null;
    }
    public function listTickets(int $userId, int $limit = 50): array
    {
        $rows = array_values(array_filter($this->tickets, fn($t) => (int) $t['user_id'] === $userId));
        usort($rows, fn($a, $b) => (int) $b['id'] <=> (int) $a['id']);
        return array_map(fn($t) => $this->findTicket((int) $t['id'], $userId), array_slice($rows, 0, min(500, max(1, $limit))));
    }
    public function listAllTickets(int $limit = 200): array
    {
        $rows = $this->tickets;
        usort($rows, fn($a, $b) => (int) $b['id'] <=> (int) $a['id']);
        return array_map(fn($t) => $this->findTicket((int) $t['id']), array_slice($rows, 0, min(1000, max(1, $limit))));
    }
    public function updateTicket(int $id, array $patch): void
    {
        foreach ($this->tickets as &$t) if ((int) $t['id'] === $id) $t = array_merge($t, $patch);
    }
    public function ticketLines(int $ticketId): array
    {
        $rows = array_values(array_filter($this->ticketLines, fn($l) => (int) $l['ticket_id'] === $ticketId));
        usort($rows, fn($a, $b) => $a['position'] <=> $b['position']);
        foreach ($rows as &$r) { $r['mains'] = json_decode((string) $r['mains'], true); $r['stars'] = json_decode((string) $r['stars'], true); }
        return $rows;
    }
    public function saveTicketLines(int $ticketId, array $lines): void
    {
        $this->ticketLines = array_values(array_filter($this->ticketLines, fn($l) => (int) $l['ticket_id'] !== $ticketId));
        foreach ($lines as $i => $line) {
            $this->ticketLines[] = ['id' => ++$this->autoId, 'ticket_id' => $ticketId, 'position' => $i, 'mains' => json_encode($line['mains']), 'stars' => json_encode($line['stars']), 'created_at' => gmdate('c')];
        }
    }
    public array $modelVersions = [];
    public array $backtests = [];
    public function ensureModelVersion(array $m): array
    {
        foreach ($this->modelVersions as $v) {
            if ($v['model_name'] === $m['model_name'] && $v['model_version'] === $m['model_version']) {
                $r = $v;
                $r['config'] = json_decode((string) $r['config'], true);
                return $r;
            }
        }
        $row = array_merge(['id' => ++$this->autoId], $m);
        $this->modelVersions[] = $row;
        $row['config'] = json_decode((string) $row['config'], true);
        return $row;
    }
    public function listModelVersions(): array
    {
        $rows = $this->modelVersions;
        foreach ($rows as &$r) { $r['config'] = json_decode((string) $r['config'], true); }
        return $rows;
    }
    public function saveBacktest(array $b): array
    {
        $row = array_merge(['id' => ++$this->autoId], $b);
        $this->backtests[] = $row;
        return ['row' => $this->findBacktest((int) $row['id']), 'created' => true];
    }
    public function findBacktest(int $id): ?array
    {
        foreach ($this->backtests as $b) {
            if ((int) $b['id'] === $id) {
                $r = $b;
                $r['report'] = json_decode((string) $r['report'], true);
                return $r;
            }
        }
        return null;
    }
    public function listBacktests(int $limit = 50): array
    {
        $rows = $this->backtests;
        usort($rows, fn($a, $b) => (int) $b['id'] <=> (int) $a['id']);
        return array_map(fn($b) => $this->findBacktest((int) $b['id']), array_slice($rows, 0, min(500, max(1, $limit))));
    }
}

/**
 * In-memory FootballRepository for tests.
 *
 * Mirrors the semantics the database implementation promises — null-preserving
 * updates, insert-once settlements, predictions frozen once settled, and
 * aggregate metrics computed over the stored rows — so a test can assert the
 * rules without a schema, and cannot pass by accidentally relying on SQL.
 */
class FootballRepositoryStub implements \AIWorkforce\Persistence\FootballRepository
{
    public array $providers = [];
    public array $competitions = [];
    public array $teams = [];
    public array $fixtures = [];
    public array $teamStatistics = [];
    public array $fixtureStatistics = [];
    public array $headToHead = [];
    public array $modelVersions = [];
    public array $calibrations = [];
    public array $predictions = [];
    public array $scoreProbabilities = [];
    public array $settlements = [];
    public array $performance = [];
    public array $syncRuns = [];
    public int $autoId = 0;
    /** @var list<string> every write, for assertions about what was touched */
    public array $writes = [];

    private function id(): int { return ++$this->autoId; }

    /** camelCase input → snake_case storage, mirroring the SQL implementation. */
    private static function normalise(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $column = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', (string) $key)) ?: (string) $key;
            if ($column !== $key && array_key_exists($column, $row)) continue;  // explicit snake wins
            if ($column === 'over15') $column = 'over_15';
            if ($column === 'over25') $column = 'over_25';
            $out[$column] = $value;
        }
        return $out;
    }

    private function find(array $rows, callable $predicate): ?array
    {
        foreach ($rows as $row) { if ($predicate($row)) return $row; }
        return null;
    }

    public function ensureProvider(string $code, array $attributes = []): array
    {
        $existing = $this->find($this->providers, fn(array $r) => ($r['provider_code'] ?? '') === $code);
        if ($existing !== null) return $existing;
        $row = ['id' => $this->id(), 'provider_code' => $code, 'display_name' => (string) ($attributes['displayName'] ?? $code),
            'status' => (string) ($attributes['status'] ?? 'NOT_CONFIGURED'), 'enabled' => !empty($attributes['enabled']) ? 1 : 0,
            'requests_used' => 0, 'requests_budget' => $attributes['requestsBudget'] ?? null, 'capabilities' => $attributes['capabilities'] ?? [],
            'backoff_until' => null, 'last_success_at' => null, 'last_failure_at' => null, 'last_error' => null,
            'created_at' => gmdate('c'), 'updated_at' => gmdate('c')];
        $this->providers[] = $row;
        $this->writes[] = 'provider:' . $code;
        return $row;
    }

    public function updateProvider(int $id, array $patch): void
    {
        foreach ($this->providers as &$row) {
            if ((int) $row['id'] !== $id) continue;
            foreach ($patch as $key => $value) {
                $column = match ($key) { 'displayName' => 'display_name', 'requestsUsed' => 'requests_used', 'requestsBudget' => 'requests_budget', 'requestsUsedDate' => 'requests_used_date',
                    'backoffUntil' => 'backoff_until', 'lastSuccessAt' => 'last_success_at', 'lastFailureAt' => 'last_failure_at',
                    'lastError' => 'last_error', 'demoMode' => 'demo_mode', default => $key };
                $row[$column] = $value;
            }
            $row['updated_at'] = gmdate('c');
            return;
        }
    }

    public function listProviders(bool $enabledOnly = false): array
    {
        return $enabledOnly ? array_values(array_filter($this->providers, fn(array $r) => !empty($r['enabled']))) : $this->providers;
    }

    public function saveCompetition(int $providerId, array $row): array
    {
        $row = self::normalise($row);
        $row['dataState'] = (string) ($row['data_state'] ?? 'DATA_UNAVAILABLE');
        $external = (string) ($row['externalId'] ?? $row['external_id'] ?? '');
        $season = $row['season'] ?? null;
        $existing = $this->find($this->competitions, fn(array $r) => (int) $r['provider_id'] === $providerId && $r['external_id'] === $external && ($r['season'] ?? null) === $season);
        $data = array_merge(['provider_id' => $providerId, 'external_id' => $external], array_intersect_key($row, array_flip(['name', 'country', 'code', 'season', 'tier', 'coefficient', 'reliability', 'payload', 'fetched_at'])),
            ['data_state' => (string) ($row['data_state'] ?? 'DATA_UNAVAILABLE')]);
        if ($existing !== null) {
            foreach ($this->competitions as &$r) { if ((int) $r['id'] === (int) $existing['id']) { $r = array_merge($r, $data, ['updated_at' => gmdate('c')]); } }
            unset($r);
            return $this->competitions[0] === $existing ? $existing : $this->find($this->competitions, fn(array $r) => (int) $r['id'] === (int) $existing['id']) ?? $existing;
        }
        $row2 = array_merge(['id' => $this->id(), 'created_at' => gmdate('c'), 'updated_at' => gmdate('c')], $data);
        $this->competitions[] = $row2;
        $this->writes[] = 'competition:' . $external;
        return $row2;
    }

    public function findCompetition(int $providerId, string $externalId, ?string $season = null): ?array
    {
        return $this->find($this->competitions, fn(array $r) => (int) $r['provider_id'] === $providerId && $r['external_id'] === $externalId
            && ($season === null || ($r['season'] ?? null) === $season));
    }

    public function saveTeam(int $providerId, array $row): array
    {
        $external = (string) ($row['externalId'] ?? $row['external_id'] ?? '');
        $existing = $this->find($this->teams, fn(array $r) => (int) $r['provider_id'] === $providerId && $r['external_id'] === $external);
        if ($existing !== null) {
            foreach ($this->teams as &$r) { if ((int) $r['id'] === (int) $existing['id']) { $r = array_merge($r, $row, ['id' => $r['id'], 'external_id' => $external, 'provider_id' => $providerId, 'updated_at' => gmdate('c')]); } }
            unset($r);
            return $this->find($this->teams, fn(array $r) => (int) $r['id'] === (int) $existing['id']) ?? $existing;
        }
        $stored = array_merge(['id' => $this->id(), 'provider_id' => $providerId, 'external_id' => $external, 'name' => (string) ($row['name'] ?? 'DATA_UNAVAILABLE'),
            'created_at' => gmdate('c'), 'updated_at' => gmdate('c')], $row);
        $this->teams[] = $stored;
        $this->writes[] = 'team:' . $external;
        return $stored;
    }

    public function findTeam(int $providerId, string $externalId): ?array
    {
        return $this->find($this->teams, fn(array $r) => (int) $r['provider_id'] === $providerId && $r['external_id'] === $externalId);
    }

    public function saveFixture(int $providerId, array $fixture): array
    {
        $external = (string) ($fixture['externalId'] ?? $fixture['external_id'] ?? '');
        if ($external === '') throw new \InvalidArgumentException('fixture requires externalId');
        $existing = $this->find($this->fixtures, fn(array $r) => (int) $r['provider_id'] === $providerId && $r['external_id'] === $external);
        $map = ['externalId' => 'external_id', 'homeTeam' => 'home_team', 'awayTeam' => 'away_team', 'homeTeamId' => 'home_team_id',
            'awayTeamId' => 'away_team_id', 'homeScore' => 'home_score', 'awayScore' => 'away_score', 'halfTimeHome' => 'half_time_home',
            'halfTimeAway' => 'half_time_away', 'homeRedCards' => 'home_red_cards', 'awayRedCards' => 'away_red_cards',
            'matchState' => 'match_state', 'extraMinute' => 'extra_minute', 'dataState' => 'data_state', 'sourceTimestamp' => 'source_timestamp',
            'competitionId' => 'competition_id'];
        $data = [];
        foreach ($fixture as $key => $value) {
            $data[$map[$key] ?? $key] = $value;
        }
        if (isset($fixture['kickoff'])) $data['kickoff_at'] = $fixture['kickoff'];
        $data['external_id'] = $external;
        $data['provider_id'] = $providerId;
        if ($existing !== null) {
            // Same freeze rules as SQL: a finished match keeps its final score and
            // its terminal status, and never reverts to an earlier partial row.
            if (($data['home_score'] ?? null) === null && ($existing['home_score'] ?? null) !== null) {
                unset($data['home_score'], $data['away_score']);
            }
            if (in_array((string) ($existing['status'] ?? ''), ['FINISHED', 'CANCELLED', 'POSTPONED'], true)
                && !in_array((string) ($data['status'] ?? ''), ['FINISHED', 'CANCELLED', 'POSTPONED'], true)) {
                unset($data['status']);
            }
            foreach ($this->fixtures as &$row) {
                if ((int) $row['id'] === (int) $existing['id']) $row = array_merge($row, $data, ['updated_at' => gmdate('c')]);
            }
            unset($row);
            return $this->find($this->fixtures, fn(array $r) => (int) $r['id'] === (int) $existing['id']) ?? $existing;
        }
        $stored = array_merge(['id' => $this->id(), 'created_at' => gmdate('c'), 'updated_at' => gmdate('c'), 'settled_at' => null], $data);
        $this->fixtures[] = $stored;
        $this->writes[] = 'fixture:' . $external;
        return $stored;
    }

    public function findFixtureById(int $id): ?array
    {
        $row = $this->find($this->fixtures, fn(array $r) => (int) $r['id'] === $id);
        return $row === null ? null : $this->decorate($row);
    }

    public function findFixture(int $providerId, string $externalId): ?array
    {
        $row = $this->find($this->fixtures, fn(array $r) => (int) $r['provider_id'] === $providerId && $r['external_id'] === $externalId);
        return $row === null ? null : $this->decorate($row);
    }

    public function listFixtures(array $filter = [], int $limit = 500): array
    {
        $rows = array_values(array_filter($this->fixtures, function (array $row) use ($filter) {
            if (!empty($filter['providerId']) && (int) $row['provider_id'] !== (int) $filter['providerId']) return false;
            if (!empty($filter['status']) && strtoupper((string) ($row['status'] ?? '')) !== strtoupper((string) $filter['status'])) return false;
            if (!empty($filter['matchState']) && strtoupper((string) ($row['match_state'] ?? '')) !== strtoupper((string) $filter['matchState'])) return false;
            if (!empty($filter['date']) && !str_starts_with((string) ($row['kickoff_at'] ?? ''), (string) $filter['date'])) return false;
            if (!empty($filter['from']) && (string) ($row['kickoff_at'] ?? '') < (string) $filter['from']) return false;
            if (!empty($filter['to']) && (string) ($row['kickoff_at'] ?? '') > (string) $filter['to']) return false;
            if (!empty($filter['competition']) && !str_starts_with(strtolower((string) ($row['competition'] ?? '')), strtolower((string) $filter['competition']))) return false;
            if (!empty($filter['team']) && stripos((string) ($row['home_team'] ?? '') . ' ' . (string) ($row['away_team'] ?? ''), (string) $filter['team']) === false) return false;
            if (!empty($filter['settledOnly']) && empty($row['settled_at'])) return false;
            if (!empty($filter['unsettledFinished']) && ((string) ($row['status'] ?? '') !== 'FINISHED' || !empty($row['settled_at']))) return false;
            return true;
        }));
        usort($rows, fn(array $a, array $b) => strcmp((string) ($a['kickoff_at'] ?? ''), (string) ($b['kickoff_at'] ?? '')));
        return array_map(fn(array $row) => $this->decorate($row), array_slice($rows, 0, max(1, $limit)));
    }

    private function decorate(array $row): array
    {
        $competition = empty($row['competition_id']) ? null : $this->find($this->competitions, fn(array $c) => (int) $c['id'] === (int) $row['competition_id']);
        $row['competition_external_id'] = $competition['external_id'] ?? null;
        $row['competition_country'] = $competition['country'] ?? null;
        $row['competition_season'] = $competition['season'] ?? null;
        $row['competition_data_state'] = $competition['data_state'] ?? 'DATA_UNAVAILABLE';
        $provider = empty($row['provider_id']) ? null : $this->find($this->providers, fn(array $p) => (int) $p['id'] === (int) $row['provider_id']);
        $row['provider_code'] = $provider['provider_code'] ?? null;
        return $row;
    }

    public function markFixtureSettled(int $id, string $at): void
    {
        foreach ($this->fixtures as &$row) { if ((int) $row['id'] === $id) $row['settled_at'] = $at; }
        unset($row);
    }

    public function linkFixtureCompetition(int $fixtureId, int $competitionId): void
    {
        foreach ($this->fixtures as &$row) { if ((int) $row['id'] === $fixtureId) $row['competition_id'] = $competitionId; }
        unset($row);
    }

    public function listFixturesAwaitingResult(int $limit = 200, ?int $providerId = null): array
    {
        $rows = array_values(array_filter($this->fixtures, function (array $row) use ($providerId) {
            if ($providerId !== null && (int) $row['provider_id'] !== $providerId) return false;
            $status = strtoupper((string) ($row['status'] ?? ''));
            if ($status === 'LIVE' || $status === 'SCHEDULED') return true;
            return $status === 'FINISHED' && (empty($row['home_score']) && $row['home_score'] !== 0 || empty($row['settled_at']));
        }));
        usort($rows, fn(array $a, array $b) => strcmp((string) ($b['kickoff_at'] ?? ''), (string) ($a['kickoff_at'] ?? '')));
        return array_map(fn(array $row) => $this->decorate($row), array_slice($rows, 0, max(1, min(500, $limit))));
    }

    public function saveTeamStatistics(int $providerId, array $row): array
    {
        $team = (string) ($row['teamExternalId'] ?? '');
        if ($team === '') throw new \InvalidArgumentException('team statistics require teamExternalId');
        $competition = $row['competitionExternalId'] ?? null;
        $season = $row['season'] ?? null;
        $key = static fn(array $r) => (int) $r['provider_id'] === $providerId && $r['team_external_id'] === $team
            && ($r['competition_external_id'] ?? null) === $competition && ($r['season'] ?? null) === $season;
        $existing = $this->find($this->teamStatistics, $key);
        $data = ['provider_id' => $providerId, 'team_external_id' => $team, 'competition_external_id' => $competition, 'season' => $season];
        foreach (['played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against', 'points', 'position', 'home_played', 'home_wins', 'home_draws',
            'home_losses', 'home_goals_for', 'home_goals_against', 'away_played', 'away_wins', 'away_draws', 'away_losses', 'away_goals_for',
            'away_goals_against', 'clean_sheets', 'failed_to_score'] as $column) {
            $camel = preg_replace_callback('/_([a-z])/', fn($m) => strtoupper($m[1]), $column) ?? $column;
            $value = $row[$camel] ?? $row[$column] ?? null;
            $data[$column] = is_numeric($value) ? (int) $value : null;
        }
        $data['team'] = (string) ($row['team'] ?? 'DATA_UNAVAILABLE');
        $data['form_last5'] = $row['formLast5'] ?? null;
        $data['form_last10'] = $row['formLast10'] ?? null;
        $data['last_matches'] = $row['lastMatches'] ?? [];
        $data['data_state'] = (string) ($row['dataState'] ?? 'DATA_UNAVAILABLE');
        $data['coverage'] = $row['coverage'] ?? [];
        $data['payload'] = $row['payload'] ?? $row;
        $data['fetched_at'] = (string) ($row['fetchedAt'] ?? gmdate('c'));
        if ($existing !== null) {
            foreach ($this->teamStatistics as &$r) { if ((int) $r['id'] === (int) $existing['id']) $r = array_merge($r, $data, ['updated_at' => gmdate('c')]); }
            unset($r);
            $this->writes[] = 'teamStatistics:update:' . $team;
            return $this->find($this->teamStatistics, $key) ?? $existing;
        }
        $stored = array_merge(['id' => $this->id(), 'created_at' => gmdate('c'), 'updated_at' => gmdate('c')], $data);
        $this->teamStatistics[] = $stored;
        $this->writes[] = 'teamStatistics:create:' . $team;
        return $stored;
    }

    public function findTeamStatistics(int $providerId, string $teamExternalId, ?string $competitionExternalId = null, ?string $season = null): ?array
    {
        return $this->find($this->teamStatistics, fn(array $r) => (int) $r['provider_id'] === $providerId && $r['team_external_id'] === $teamExternalId
            && ($r['competition_external_id'] ?? null) === $competitionExternalId
            && ($season === null || ($r['season'] ?? null) === $season));
    }

    public function listTeamRecentResults(int $providerId, string $teamExternalId, int $limit = 10): array
    {
        if ($teamExternalId === '') return [];
        $rows = array_values(array_filter($this->fixtures, fn(array $r) => (int) $r['provider_id'] === $providerId
            && strtoupper((string) ($r['status'] ?? '')) === 'FINISHED'
            && $r['home_score'] !== null && $r['away_score'] !== null
            && (($r['home_team_id'] ?? null) === $teamExternalId || ($r['away_team_id'] ?? null) === $teamExternalId)));
        usort($rows, fn(array $a, array $b) => strcmp((string) ($b['kickoff_at'] ?? ''), (string) ($a['kickoff_at'] ?? '')));
        return array_slice($rows, 0, max(1, min(50, $limit)));
    }

    public function saveFixtureStatistics(int $fixtureId, int $providerId, string $kind, array $payload, array $coverage = []): array
    {
        $existing = $this->find($this->fixtureStatistics, fn(array $r) => (int) $r['fixture_id'] === $fixtureId && (int) $r['provider_id'] === $providerId && $r['kind'] === $kind);
        $data = ['fixture_id' => $fixtureId, 'provider_id' => $providerId, 'kind' => $kind, 'payload' => $payload,
            'data_state' => $coverage === [] ? 'DATA_UNAVAILABLE' : (string) ($coverage['state'] ?? 'LIMITED_DATA'),
            'coverage' => $coverage, 'fetched_at' => gmdate('c')];
        if ($existing !== null) {
            foreach ($this->fixtureStatistics as &$r) { if ((int) $r['id'] === (int) $existing['id']) $r = array_merge($r, $data); }
            unset($r);
            return $this->find($this->fixtureStatistics, fn(array $x) => (int) $x['id'] === (int) $existing['id']) ?? $existing;
        }
        $stored = array_merge(['id' => $this->id(), 'created_at' => gmdate('c')], $data);
        $this->fixtureStatistics[] = $stored;
        return $stored;
    }

    public function findFixtureStatistics(int $fixtureId, ?string $kind = null): ?array
    {
        return $this->find($this->fixtureStatistics, fn(array $r) => (int) $r['fixture_id'] === $fixtureId && ($kind === null || $r['kind'] === $kind));
    }

    public function saveHeadToHead(int $providerId, array $row): array
    {
        $home = (string) ($row['homeTeamExternalId'] ?? '');
        $away = (string) ($row['awayTeamExternalId'] ?? '');
        $competition = $row['competitionExternalId'] ?? null;
        $key = fn(array $r) => (int) $r['provider_id'] === $providerId && $r['home_team_external_id'] === $home
            && $r['away_team_external_id'] === $away && ($r['competition_external_id'] ?? null) === $competition;
        $existing = $this->find($this->headToHead, $key);
        $normalised = self::normalise($row);
        $data = array_merge(['provider_id' => $providerId, 'home_team_external_id' => $home, 'away_team_external_id' => $away,
            'competition_external_id' => $competition], array_intersect_key($normalised, array_flip(['meetings', 'home_wins', 'draws', 'away_wins', 'avg_home_goals',
            'avg_away_goals', 'both_teams_scored', 'over_15', 'over_25', 'oldest_kickoff', 'newest_kickoff', 'sample_age_days', 'weight', 'matches', 'fetched_at'])));
        $data['data_state'] = (string) ($row['dataState'] ?? 'LIMITED_DATA');
        if ($existing !== null) {
            foreach ($this->headToHead as &$r) { if ((int) $r['id'] === (int) $existing['id']) $r = array_merge($r, $data, ['updated_at' => gmdate('c')]); }
            unset($r);
            return $this->find($this->headToHead, $key) ?? $existing;
        }
        $stored = array_merge(['id' => $this->id(), 'created_at' => gmdate('c')], $data);
        $this->headToHead[] = $stored;
        return $stored;
    }

    public function findHeadToHead(int $providerId, string $homeTeamExternalId, string $awayTeamExternalId, ?string $competitionExternalId = null): ?array
    {
        return $this->find($this->headToHead, fn(array $r) => (int) $r['provider_id'] === $providerId
            && $r['home_team_external_id'] === $homeTeamExternalId && $r['away_team_external_id'] === $awayTeamExternalId
            && ($competitionExternalId === null || ($r['competition_external_id'] ?? null) === $competitionExternalId));
    }

    public function saveModelVersion(array $row): array
    {
        $row = self::normalise($row);
        $name = (string) ($row['model_name'] ?? $row['modelName'] ?? '');
        $version = (string) ($row['model_version'] ?? $row['modelVersion'] ?? '');
        if ($name === '' || $version === '') throw new \InvalidArgumentException('model version requires model_name and model_version');
        $existing = $this->findModelVersionByName($name, $version);
        if ($existing !== null) {
            foreach ($this->modelVersions as &$r) { if ((int) $r['id'] === (int) $existing['id']) $r = array_merge($r, $row, ['id' => $r['id'], 'status' => (string) ($row['status'] ?? $r['status']), 'updated_at' => gmdate('c')]); }
            unset($r);
            return $this->findModelVersionByName($name, $version) ?? $existing;
        }
        $stored = array_merge(['id' => $this->id(), 'model_id' => 'football-model-' . $this->autoId, 'model_name' => $name, 'model_version' => $version,
            'status' => 'DRAFT', 'created_at' => gmdate('c'), 'updated_at' => gmdate('c')], $row);
        $this->modelVersions[] = $stored;
        $this->writes[] = 'model:' . $name . '@' . $version . '=' . $stored['status'];
        return $stored;
    }

    public function findModelVersion(int $id): ?array
    {
        return $this->find($this->modelVersions, fn(array $r) => (int) $r['id'] === $id);
    }

    public function findModelVersionByName(string $modelName, string $modelVersion): ?array
    {
        return $this->find($this->modelVersions, fn(array $r) => ($r['model_name'] ?? '') === $modelName && ($r['model_version'] ?? '') === $modelVersion);
    }

    public function listModelVersions(?string $status = null, int $limit = 50): array
    {
        $rows = $status === null ? $this->modelVersions : array_values(array_filter($this->modelVersions, fn(array $r) => ($r['status'] ?? '') === $status));
        usort($rows, fn(array $a, array $b) => (int) $b['id'] <=> (int) $a['id']);
        return array_slice($rows, 0, max(1, min(200, $limit)));
    }

    public function updateModelVersion(int $id, array $patch): void
    {
        $patch = self::normalise($patch);
        foreach ($this->modelVersions as &$row) {
            if ((int) $row['id'] === $id) { $row = array_merge($row, $patch, ['updated_at' => gmdate('c')]); return; }
        }
        unset($row);
    }

    public function saveCalibration(array $row): array
    {
        $row = self::normalise($row);
        $modelId = (int) ($row['model_version_id'] ?? 0);
        if ($modelId <= 0) throw new \InvalidArgumentException('calibration requires model_version_id');
        $version = (string) ($row['calibration_version'] ?? '');
        $existing = $this->find($this->calibrations, fn(array $r) => (int) $r['model_version_id'] === $modelId && ($r['calibration_version'] ?? '') === $version);
        if ($existing !== null) return $existing;   // deterministic version → stored once
        $stored = array_merge(['id' => $this->id(), 'status' => 'PENDING', 'created_at' => gmdate('c'), 'sample_size' => 0], $row, ['model_version_id' => $modelId, 'calibration_version' => $version]);
        $this->calibrations[] = $stored;
        $this->writes[] = 'calibration:' . $modelId . '@' . $version;
        return $stored;
    }

    public function findCalibration(int $id): ?array
    {
        return $this->find($this->calibrations, fn(array $r) => (int) $r['id'] === $id);
    }

    public function listCalibrations(?int $modelVersionId = null, ?string $status = null, int $limit = 50): array
    {
        $rows = array_values(array_filter($this->calibrations, fn(array $r) => ($modelVersionId === null || (int) $r['model_version_id'] === $modelVersionId)
            && ($status === null || ($r['status'] ?? '') === $status)));
        usort($rows, fn(array $a, array $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return array_slice($rows, 0, max(1, min(200, $limit)));
    }

    public function updateCalibration(int $id, array $patch): void
    {
        $patch = self::normalise($patch);
        foreach ($this->calibrations as &$row) {
            if ((int) $row['id'] === $id) { $row = array_merge($row, $patch); return; }
        }
        unset($row);
    }

    public function savePrediction(array $row): array
    {
        $row = self::normalise($row);
        $id = (string) ($row['id'] ?? '');
        if ($id === '') throw new \InvalidArgumentException('prediction requires an id');
        $existing = $this->findPrediction($id);
        if ($existing !== null && in_array((string) ($existing['settlement_state'] ?? 'OPEN'), ['SETTLED', 'VOID'], true)) {
            return $existing;      // frozen: an evaluated prediction is immutable
        }
        if ($existing !== null) {
            foreach ($this->predictions as &$r) { if ((string) $r['id'] === $id) $r = array_merge($r, $row, ['updated_at' => gmdate('c')]); }
            unset($r);
            $this->writes[] = 'prediction:update:' . $id;
            return $this->findPrediction($id) ?? $existing;
        }
        $stored = array_merge(['id' => $id, 'created_at' => gmdate('c'), 'settlement_state' => 'OPEN'], $row);
        $this->predictions[] = $stored;
        $this->writes[] = 'prediction:create:' . $id;
        return $stored;
    }

    public function findPrediction(string $id): ?array
    {
        return $this->find($this->predictions, fn(array $r) => (string) ($r['id'] ?? '') === $id);
    }

    public function listPredictions(array $filter = [], int $limit = 500): array
    {
        $rows = array_values(array_filter($this->predictions, function (array $row) use ($filter) {
            if (!empty($filter['fixtureId']) && (int) ($row['fixture_id'] ?? 0) !== (int) $filter['fixtureId']) return false;
            if (!empty($filter['kind']) && (string) ($row['prediction_kind'] ?? '') !== (string) $filter['kind']) return false;
            if (!empty($filter['eligibility']) && (string) ($row['eligibility'] ?? '') !== (string) $filter['eligibility']) return false;
            if (!empty($filter['modelVersionId']) && (int) ($row['model_version_id'] ?? 0) !== (int) $filter['modelVersionId']) return false;
            if (!empty($filter['settlementState']) && (string) ($row['settlement_state'] ?? '') !== (string) $filter['settlementState']) return false;
            if (!empty($filter['date']) && !str_starts_with((string) ($row['kickoff_at'] ?? ''), (string) $filter['date'])) return false;
            if (!empty($filter['from']) && (string) ($row['generated_at'] ?? '') < (string) $filter['from']) return false;
            if (!empty($filter['to']) && (string) ($row['generated_at'] ?? '') > (string) $filter['to']) return false;
            return true;
        }));
        usort($rows, fn(array $a, array $b) => strcmp((string) ($b['generated_at'] ?? ''), (string) ($a['generated_at'] ?? '')));
        return array_slice($rows, 0, max(1, min(2000, $limit)));
    }

    public function saveScoreProbabilities(string $predictionId, array $rows): void
    {
        $this->scoreProbabilities = array_values(array_filter($this->scoreProbabilities, fn(array $r) => (string) $r['prediction_id'] !== $predictionId));
        foreach ($rows as $row) {
            $home = (int) ($row['home'] ?? $row['home_goals'] ?? -1);
            $away = (int) ($row['away'] ?? $row['away_goals'] ?? -1);
            if ($home < 0 || $away < 0) continue;
            $this->scoreProbabilities[] = ['prediction_id' => $predictionId, 'home_goals' => $home, 'away_goals' => $away,
                'probability' => (float) ($row['probability'] ?? 0), 'rank' => (int) ($row['rank'] ?? 0),
                'is_prediction' => !empty($row['isPrediction']) ? 1 : 0, 'created_at' => gmdate('c')];
        }
    }

    public function listScoreProbabilities(string $predictionId, int $limit = 20): array
    {
        $rows = array_values(array_filter($this->scoreProbabilities, fn(array $r) => (string) $r['prediction_id'] === $predictionId));
        usort($rows, fn(array $a, array $b) => (int) $a['rank'] <=> (int) $b['rank']);
        return array_slice($rows, 0, max(1, min(100, $limit)));
    }

    public function saveSettlement(array $row): array
    {
        $predictionId = (string) ($row['prediction_id'] ?? '');
        if ($predictionId === '') throw new \InvalidArgumentException('settlement requires prediction_id');
        $existing = $this->findSettlement($predictionId);
        if ($existing !== null) return ['row' => $existing, 'created' => false];
        $stored = array_merge(['id' => $this->id(), 'created_at' => gmdate('c')], self::normalise($row));
        $this->settlements[] = $stored;
        $this->writes[] = 'settlement:' . $predictionId;
        return ['row' => $stored, 'created' => true];
    }

    public function findSettlement(string $predictionId): ?array
    {
        return $this->find($this->settlements, fn(array $r) => (string) ($r['prediction_id'] ?? '') === $predictionId);
    }

    public function listSettlements(array $filter = [], int $limit = 2000): array
    {
        $rows = array_values(array_filter($this->settlements, function (array $row) use ($filter) {
            if (!empty($filter['modelVersionId']) && (int) ($row['model_version_id'] ?? 0) !== (int) $filter['modelVersionId']) return false;
            if (!empty($filter['fixtureId']) && (int) ($row['fixture_id'] ?? 0) !== (int) $filter['fixtureId']) return false;
            if (!empty($filter['from']) && (string) ($row['settled_at'] ?? '') < (string) $filter['from']) return false;
            if (!empty($filter['to']) && (string) ($row['settled_at'] ?? '') > (string) $filter['to']) return false;
            return true;
        }));
        usort($rows, fn(array $a, array $b) => strcmp((string) ($b['settled_at'] ?? ''), (string) ($a['settled_at'] ?? '')));
        return array_slice($rows, 0, max(1, min(5000, $limit)));
    }

    public function settlementAggregates(array $filter = []): array
    {
        $rows = $this->listSettlements($filter, 5000);
        $evaluated = count($rows);
        $correctResults = count(array_filter($rows, fn(array $r) => (int) ($r['correct_result'] ?? 0) === 1));
        $correctScores = count(array_filter($rows, fn(array $r) => (int) ($r['correct_exact_score'] ?? 0) === 1));
        $confidence = array_values(array_filter(array_map(fn(array $r) => $r['confidence'] ?? null, $rows), 'is_numeric'));
        $quality = array_values(array_filter(array_map(fn(array $r) => $r['data_quality_score'] ?? null, $rows), 'is_numeric'));
        $brier = array_values(array_filter(array_map(fn(array $r) => $r['brier'] ?? null, $rows), 'is_numeric'));
        $logLoss = array_values(array_filter(array_map(fn(array $r) => $r['log_loss'] ?? null, $rows), 'is_numeric'));
        return [
            'evaluated' => $evaluated,
            'correctResults' => $correctResults,
            'correctScores' => $correctScores,
            'averageConfidence' => $confidence === [] ? null : round(array_sum($confidence) / count($confidence), 2),
            'averageDataQuality' => $quality === [] ? null : round(array_sum($quality) / count($quality), 2),
            'brier' => $evaluated > 0 && count($brier) === $evaluated ? round(array_sum($brier) / $evaluated, 6) : null,
            'logLoss' => $evaluated > 0 && count($logLoss) === $evaluated ? round(array_sum($logLoss) / $evaluated, 6) : null,
        ];
    }

    public function listCalibrationSamples(array $filter = []): array
    {
        $rows = [];
        foreach ($this->listSettlements($filter, (int) ($filter['limit'] ?? 5000)) as $settlement) {
            $prediction = $this->findPrediction((string) ($settlement['prediction_id'] ?? ''));
            if ($prediction === null) continue;
            if ($settlement['actual_home_score'] === null || $settlement['actual_away_score'] === null) continue;
            $rows[] = array_merge($settlement, [
                'raw_home' => $prediction['raw_home'] ?? null, 'raw_draw' => $prediction['raw_draw'] ?? null, 'raw_away' => $prediction['raw_away'] ?? null,
                'probability_home' => $prediction['probability_home'] ?? null, 'probability_draw' => $prediction['probability_draw'] ?? null,
                'probability_away' => $prediction['probability_away'] ?? null, 'confidence' => $prediction['confidence'] ?? null,
                'confidence_basis' => $prediction['confidence_basis'] ?? null, 'calibration_state' => $prediction['calibration_state'] ?? null,
                'data_quality_band' => $prediction['data_quality_band'] ?? null, 'eligibility' => $prediction['eligibility'] ?? null,
                'kickoff_at' => $prediction['kickoff_at'] ?? null, 'generated_at' => $prediction['generated_at'] ?? null,
            ]);
        }
        return $rows;
    }

    public function savePerformanceSnapshot(array $row): array
    {
        $row = self::normalise($row);
        $key = fn(array $r) => (int) ($r['model_version_id'] ?? 0) === (int) ($row['model_version_id'] ?? 0)
            && (int) ($r['window_days'] ?? 0) === (int) ($row['window_days'] ?? 30)
            && (string) ($r['window_start'] ?? '') === (string) ($row['window_start'] ?? '')
            && (string) ($r['window_end'] ?? '') === (string) ($row['window_end'] ?? '');
        $existing = $this->find($this->performance, $key);
        if ($existing !== null) {
            foreach ($this->performance as &$r) { if ((int) $r['id'] === (int) $existing['id']) $r = array_merge($r, $row); }
            unset($r);
            return $this->find($this->performance, $key) ?? $existing;
        }
        $stored = array_merge(['id' => $this->id(), 'window_days' => 30, 'computed_at' => gmdate('c')], $row);
        $this->performance[] = $stored;
        return $stored;
    }

    public function latestPerformanceSnapshot(int $windowDays, ?int $modelVersionId = null): ?array
    {
        $rows = array_values(array_filter($this->performance, fn(array $r) => (int) ($r['window_days'] ?? 0) === $windowDays
            && ($modelVersionId === null || (int) ($r['model_version_id'] ?? 0) === $modelVersionId)));
        usort($rows, fn(array $a, array $b) => strcmp((string) ($b['computed_at'] ?? ''), (string) ($a['computed_at'] ?? '')));
        return $rows[0] ?? null;
    }

    public function startSyncRun(array $run): ?array
    {
        $key = (string) ($run['executionKey'] ?? '');
        if ($key === '') throw new \InvalidArgumentException('sync run requires executionKey');
        if ($this->find($this->syncRuns, fn(array $r) => $r['execution_key'] === $key) !== null) return null;
        $stored = array_merge(['id' => $this->id(), 'status' => 'RUNNING', 'records_processed' => 0, 'records_created' => 0, 'records_updated' => 0,
            'requests_made' => 0, 'errors' => [], 'started_at' => gmdate('c'), 'ended_at' => null, 'attempts' => 1,
            'execution_key' => $key, 'job_type' => $run['jobType'] ?? null, 'window_start' => $run['windowStart'] ?? null,
            'window_end' => $run['windowEnd'] ?? null, 'provider_code' => $run['providerCode'] ?? null, 'next_run_at' => null], $run);
        unset($stored['executionKey'], $stored['jobType'], $stored['windowStart'], $stored['windowEnd']);
        $this->syncRuns[] = $stored;
        return $stored;
    }

    public function finishSyncRun(string $executionKey, array $result): void
    {
        foreach ($this->syncRuns as &$row) {
            if ((string) $row['execution_key'] === $executionKey) {
                $row = array_merge($row, ['status' => (string) ($result['status'] ?? 'COMPLETED'), 'records_processed' => (int) ($result['processed'] ?? 0),
                    'records_created' => (int) ($result['created'] ?? 0), 'records_updated' => (int) ($result['updated'] ?? 0),
                    'requests_made' => (int) ($result['requests'] ?? 0), 'errors' => (array) ($result['errors'] ?? []),
                    'rate_limit_remaining' => $result['rateLimitRemaining'] ?? null, 'retry_after_seconds' => $result['retryAfterSeconds'] ?? null,
                    'next_run_at' => $result['nextRunAt'] ?? null, 'ended_at' => gmdate('c'), 'attempts' => (int) $row['attempts'] + 1]);
                return;
            }
        }
        unset($row);
    }

    public function listSyncRuns(?string $jobType = null, int $limit = 50): array
    {
        $rows = $jobType === null ? $this->syncRuns : array_values(array_filter($this->syncRuns, fn(array $r) => (string) ($r['job_type'] ?? '') === $jobType));
        usort($rows, fn(array $a, array $b) => strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? '')));
        return array_slice($rows, 0, max(1, min(500, $limit)));
    }

    public function pruneSyncLogs(int $olderThanDays = 120): int
    {
        $cutoff = gmdate('c', time() - max(1, $olderThanDays) * 86400);
        $before = count($this->syncRuns);
        $this->syncRuns = array_values(array_filter($this->syncRuns, fn(array $r) => (string) ($r['started_at'] ?? '') >= $cutoff));
        return $before - count($this->syncRuns);
    }

    public function pruneOrphanScoreRows(): int
    {
        $before = count($this->scoreProbabilities);
        $this->scoreProbabilities = array_values(array_filter($this->scoreProbabilities, fn(array $r) => $this->findPrediction((string) $r['prediction_id']) !== null));
        return $before - count($this->scoreProbabilities);
    }

    public function lastSyncRun(?string $jobType = null, ?int $providerId = null): ?array
    {
        $rows = $this->listSyncRuns($jobType, 500);
        if ($providerId !== null) $rows = array_values(array_filter($rows, fn(array $r) => (int) ($r['provider_id'] ?? 0) === $providerId));
        return $rows[0] ?? null;
    }
}

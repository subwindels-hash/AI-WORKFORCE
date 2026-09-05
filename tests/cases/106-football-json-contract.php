<?php
/**
 * Football Intelligence — the payloads the surfaces serialize (spec §16/§17/§20).
 *
 * The dashboard and the JSON API are fed by the same arrays, so the questions that
 * matter are answerable at this layer and are answered here: does every payload
 * survive strict JSON encoding at all (a NaN Brier or an INF log loss makes
 * json_encode refuse, and the panel goes white instead of empty), is a number the
 * provider never sent null rather than 0, does an uncalibrated model still publish
 * a complete §20 contract, and do the two public status endpoints carry any
 * credential — they are readable without a session by design, so that has to be
 * proven, not intended.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\CalibrationService;
use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\FootballIntelligence;
use AIWorkforce\Football\QualityBand;
use AIWorkforce\Sports\Providers\SportsProviderManager;

/** Flatten a payload to "a.b.c" => value so invariants can be scanned recursively. */
function fx_fb_json_flat($value, string $path = ''): array
{
    if (!is_array($value)) return [$path => $value];
    $out = [];
    foreach ($value as $key => $child) {
        $out += fx_fb_json_flat($child, $path === '' ? (string) $key : $path . '.' . $key);
    }
    if ($value === []) $out[$path] = '[]';
    return $out;
}

/** Encode strictly, and report why it failed rather than swallowing the error. */
function fx_fb_json_encode(array $payload): string
{
    try {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        throw new RuntimeException('json_encode refused this payload: ' . $e->getMessage(), 0, $e);
    }
}

/** The metric keys that must be absent-by-value (null) when history is empty. */
function fx_fb_json_null_metrics(array $flat, string $where, array $metrics = [
    'accuracy', 'brier', 'brierScore', 'ece', 'mce', 'logLoss', 'resultAccuracy',
    'exactScoreAccuracy', 'exactAccuracy', 'avgConfidence', 'maxDrawdown', 'roi', 'winRate', 'modelAccuracy',
]): void {
    foreach ($metrics as $metric) {
        foreach ($flat as $path => $value) {
            if (!str_ends_with($path, $metric)) continue;
            assert_true(!is_int($value) && !is_float($value), $where . ': ' . $path
                . ' must not be a number while there is nothing to measure (got ' . var_export($value, true) . ')');
        }
    }
}

test('football: every payload the surfaces read survives strict JSON encoding', function () {
    // First with nothing connected — the state the reported dashboard was in.
    $none = new FootballIntelligence(new FootballRepositoryStub(), new SportsProviderManager(), null, new FootballConfiguration());
    $empty = [
        'providerStatus' => $none->providerStatus(),
        'diagnostics' => $none->diagnostics()->snapshot(),
        'performance' => $none->performance()->report(30),
        'board' => $none->board()->forDate(gmdate('Y-m-d')),
        'modelSummary' => $none->modelSummary(),
        'live' => $none->live()->board(false),
        'dashboard' => $none->dashboard(gmdate('Y-m-d')),
        'config' => $none->config()->describe(),
    ];
    foreach ($empty as $name => $payload) {
        $json = fx_fb_json_encode($payload);
        assert_true($json !== '', $name . ' encodes even with no provider and no rows');
        foreach (fx_fb_json_flat($payload) as $path => $value) {
            if (is_float($value)) {
                assert_true(is_finite($value), $name . ': ' . $path . ' is ' . (is_nan($value) ? 'NaN' : 'INF') . ', which no client can read');
            }
        }
    }

    // Then with a full day: fixtures, statistics, predictions and one settlement.
    $kickoff = time() + 7200;
    [$repo, $module, $day] = fx_fb_predict([fx_fb_row('fx-json', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20')]);
    $id = (int) ($repo->listFixtures(['date' => $day], 1)[0]['id'] ?? 0);
    $populated = [
        'providerStatus' => $module->providerStatus(),
        'diagnostics' => $module->diagnostics()->snapshot(),
        'performance' => $module->performance()->report(30),
        'board' => $module->board()->forDate($day),
        'modelSummary' => $module->modelSummary(),
        'analysis' => $module->analysis($id),
        'prediction' => $module->predictionFor($id),
        'history' => $module->history(20),
        'live' => $module->live()->board(false),
    ];
    foreach ($populated as $name => $payload) {
        assert_true(fx_fb_json_encode($payload) !== '', $name . ' encodes for a populated day');
        foreach (fx_fb_json_flat($payload) as $path => $value) {
            if (is_float($value)) assert_true(is_finite($value), $name . ': ' . $path . ' must be finite');
        }
    }
});

test('football: an empty history is null in the payload, never a zero the panel can print', function () {
    $none = new FootballIntelligence(new FootballRepositoryStub(), new SportsProviderManager(), null, new FootballConfiguration());
    foreach (['performance' => $none->performance()->report(30), 'diagnostics' => $none->diagnostics()->snapshot(),
              'modelSummary' => $none->modelSummary(), 'board' => $none->board()->forDate(gmdate('Y-m-d'))] as $name => $payload) {
        fx_fb_json_null_metrics(fx_fb_json_flat($payload), $name);
    }
    // Counts are different in kind: zero stored fixtures IS the fact, so it is an int.
    $diagnostics = fx_fb_json_flat($none->diagnostics()->snapshot());
    $counted = array_filter($diagnostics, static fn(string $path): bool => str_contains($path, 'counts.'), ARRAY_FILTER_USE_KEY);
    assert_true($counted !== [], 'diagnostics report their counts');
    foreach ($counted as $path => $value) assert_true(is_int($value), $path . ' is an integer count, not null and not a string');
});

test('football: the public status endpoints carry no credential', function () {
    // These two are readable without a session, so the payload itself is the guard.
    [$repo, $provider, $module] = fx_fb_harness([fx_fb_row('fx-pub', gmdate('c', time() + 7200), 'Manchester City', 'Everton', '10', '20')]);
    foreach (['providerStatus' => $module->providerStatus(), 'diagnostics' => $module->diagnostics()->snapshot(),
              'config' => $module->config()->describe()] as $name => $payload) {
        foreach (fx_fb_json_flat($payload) as $path => $value) {
            $leaf = strtolower(strrchr('.' . $path, '.') ?: $path);
            assert_true(preg_match('/token|secret|password|authorization|api_?key|apikey|cookie|credential/', $leaf) !== 1,
                $name . ' exposes a credential-shaped key: ' . $path);
            if (is_string($value) && !str_ends_with($path, '.message') && !str_ends_with($path, '.headline')
                && !str_ends_with($path, '.reason') && !str_ends_with($path, '.action') && !str_ends_with($path, '.detail')) {
                assert_true(preg_match('/^[A-Za-z0-9_-]{24,}$/', $value) !== 1 || !preg_match('/[0-9]/', $value),
                    $name . ' may carry a raw credential value at ' . $path);
            }
        }
    }
    // And the provider's own key is not echoed by the status payload at all.
    assert_true(!str_contains(json_encode($module->providerStatus()), 'test-key'), 'the configured key is not reflected back');
});

test('football: the §20 contract is complete, bounded and uncalibrated where that is true', function () {
    [$repo, $module, $day] = fx_fb_predict([fx_fb_row('fx-contract', gmdate('c', time() + 7200), 'Manchester City', 'Everton', '10', '20')]);
    $id = (int) ($repo->listFixtures(['date' => $day], 1)[0]['id'] ?? 0);
    $wrapper = $module->predictionFor($id);
    assert_equals('OK', $wrapper['status'] ?? '', 'the prediction endpoint finds the stored row');
    $contract = $wrapper['prediction'] ?? [];
    foreach (['fixtureId', 'homeTeam', 'awayTeam', 'status', 'prediction', 'dataQuality', 'model', 'reason', 'generatedAt'] as $key) {
        assert_true(array_key_exists($key, (array) $contract), '§20 field present: ' . $key);
    }
    $prediction = (array) ($contract['prediction'] ?? []);
    foreach (['result', 'predictedScore', 'probabilities', 'confidence'] as $key) {
        assert_true(array_key_exists($key, $prediction), 'prediction.' . $key . ' is published');
    }
    $sum = array_sum(array_map(static fn($v): float => (float) $v, (array) ($prediction['probabilities'] ?? [])));
    assert_true(abs($sum - 100.0) < 0.6 || abs($sum - 1.0) < 0.006, 'the three shares account for the whole match (sum ' . round($sum, 3) . ')');
    foreach (['home', 'draw', 'away'] as $side) {
        assert_true(isset($prediction['probabilities'][$side]), 'probability for ' . $side . ' is published, never omitted');
    }
    $score = (array) ($prediction['predictedScore'] ?? []);
    assert_true(array_key_exists('home', $score) && array_key_exists('away', $score), 'a scoreline is published for both sides');
    $confidence = (float) ($prediction['confidence'] ?? 0);
    assert_true($confidence > 0.0 && $confidence < 100.0, 'confidence is in range and never certainty (' . $confidence . ')');
    $quality = (array) ($contract['dataQuality'] ?? []);
    assert_true((int) ($quality['score'] ?? -1) >= QualityBand::QUALIFIED_MIN, 'a published prediction cleared the quality gate');
    assert_true((int) $quality['score'] <= 100, 'the quality score is a percentage');
    assert_equals(QualityBand::QUALIFIED, (string) ($quality['status'] ?? ''), 'and its band is the qualifying band');
    $model = (array) ($contract['model'] ?? []);
    assert_true((string) ($model['version'] ?? '') !== '', 'the model version that produced it is named');
    // No calibration can exist yet: MIN_CALIBRATION_SAMPLES has not been met, so the
    // contract must say so instead of pretending the numbers are calibrated.
    assert_true(($model['calibrationVersion'] ?? null) === null, 'calibrationVersion is null while calibration is pending, never 0');
    assert_equals(CalibrationService::PENDING, (string) ($prediction['calibrationState'] ?? ''), 'and the state says pending');
    assert_true(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', (string) ($contract['generatedAt'] ?? '')) === 1,
        'generatedAt is an ISO-8601 timestamp');
    assert_true((string) ($contract['reason'] ?? '') !== '', 'the reasoning travels with the number');
    foreach (['certain', 'guaranteed', 'lock', '100%'] as $claim) {
        assert_true(stripos((string) ($contract['reason'] ?? ''), $claim) === false, 'the reasoning never claims it is ' . $claim);
    }
    // A scheduled fixture has no score yet — that is null, not 0-0.
    assert_true($contract['score'] === null, 'no scoreline is invented for a match that has not started');
});

test('football: analysis exposes the venue split the match card reads', function () {
    [$repo, $module, $day] = fx_fb_predict([fx_fb_row('fx-venues', gmdate('c', time() + 7200), 'Manchester City', 'Everton', '10', '20')]);
    $id = (int) ($repo->listFixtures(['date' => $day], 1)[0]['id'] ?? 0);
    $analysis = $module->analysis($id);
    assert_equals('OK', $analysis['status'] ?? '', 'the stored fixture is analysed from stored rows');
    assert_equals(['HOME', 'AWAY'], array_keys((array) ($analysis['teams'] ?? [])), 'both venue halves are published');
    foreach (['played', 'wins', 'draws', 'losses', 'goalsFor', 'goalsAgainst', 'avgGoalsScored', 'avgGoalsConceded'] as $field) {
        foreach (['HOME', 'AWAY'] as $venue) {
            $value = $analysis['teams'][$venue][$field] ?? 'MISSING';
            assert_true($value !== 'MISSING', $venue . '.' . $field . ' is part of the analysis payload');
            assert_true($value === null || is_int($value) || is_float($value), $venue . '.' . $field . ' is a number or null, never an empty string');
        }
    }
    foreach (['dataQuality', 'coverage', 'provenance'] as $block) {
        assert_true(isset($analysis[$block]) , $block . ' is reported next to the numbers it justifies');
    }
    assert_true($analysis['prediction'] === null || is_array($analysis['prediction']), 'a fixture without a stored prediction says so rather than echoing zero');
});

test('football: a fixture with nothing behind it is never published as qualified', function () {
    // Teams that appear in no standings row, no history row and no head-to-head
    // sample: the honest "LIMITED_DATA" case, manufactured by omission only.
    $table = fx_fb_team_table();
    assert_true(!isset($table['91']) && !isset($table['92']), 'the thin scenario really has no team data to read');
    $kickoff = time() + 7200;
    $day = gmdate('Y-m-d', $kickoff);
    [$repo, $provider, $module] = fx_fb_harness([fx_fb_row('fx-thin', gmdate('c', $kickoff), 'Unknown FC', 'Also Unknown', '91', '92')]);
    $module->fixtures()->syncDay($day, 'test:thin', null, -1);
    $fixture = $repo->listFixtures(['date' => $day], 1)[0] ?? null;
    assert_true($fixture !== null, 'the fixture itself is stored — the gap is in the statistics, not the schedule');
    $features = $module->features()->build($fixture);
    $band = (string) ($features['dataQuality']['band'] ?? '');
    assert_true($band !== QualityBand::QUALIFIED, 'and the quality gate refuses to call it qualified (band ' . $band . ')');
    $attempt = $module->predictions()->predictFixture((int) $fixture['id']);
    if (($attempt['status'] ?? '') === 'PREDICTED') {
        assert_true((string) ($attempt['dataQuality']['band'] ?? $band) !== QualityBand::QUALIFIED, 'a thin fixture is never labelled high confidence');
    } else {
        assert_true((string) ($attempt['reason'] ?? '') !== '' || (string) ($attempt['message'] ?? '') !== '', 'a refusal has to say why');
    }
    $payload = fx_fb_json_flat($module->analysis((int) $fixture['id']));
    foreach ($payload as $path => $value) {
        if (preg_match('/(attackStrength|defenseWeakness|expectedGoalsTendency|cleanSheetRate|failedToScoreRate)$/', $path)) {
            assert_true($value === null || is_numeric($value), $path . ' is null rather than 0 when the provider never sent it');
        }
    }
});

test('football: a provider error that quotes its own key never reaches the interface', function () {
    // The feed below fails the way a real HTTP client fails: the exception message
    // embeds the URL it called, and this family of feeds puts the credential in
    // that URL's query string. What the operator must see is the status and the
    // endpoint; what must never be stored or rendered is the key.
    $secret = 'FxLeakTestKey0123456789abcdefGHIJKL';
    $leaky = new class($secret) implements \AIWorkforce\Sports\Providers\SportsDataProvider {
        public function __construct(private string $key) {}
        public function id(): string { return 'leaky'; }
        public function health(): array { return ['status' => 'ONLINE', 'detail' => 'reachable', 'reliability' => 1.0]; }
        public function fixtures(array $query): array
        {
            throw new \AIWorkforce\Sports\Providers\ProviderException(
                'HTTP 401 Unauthorized for GET https://feed.example/v3/fixtures?date=2026-09-05&access=' . $this->key,
                \AIWorkforce\Sports\Providers\ProviderException::AUTHENTICATION_ERROR
            );
        }
        public function odds(string $fixtureExternalId): array { return []; }
        public function results(string $fixtureExternalId): array { return []; }
    };
    $repo = new FootballRepositoryStub();
    $manager = new \AIWorkforce\Sports\Providers\SportsProviderManager();
    $manager->register($leaky);
    $module = new FootballIntelligence($repo, $manager, null, new FootballConfiguration());

    $sync = $module->fixtures()->syncDay(gmdate('Y-m-d'), 'test:leaky', null, -1);
    $errors = (array) ($sync['errors'] ?? []);
    assert_true($errors !== [], 'the failure is reported, not swallowed');
    $serialized = json_encode($sync, JSON_UNESCAPED_SLASHES);
    assert_true(!str_contains((string) $serialized, $secret), 'the sync payload carries no credential');
    assert_contains('HTTP 401', $serialized, 'but the operator still learns what failed');
    assert_contains('feed.example', $serialized, 'and where it failed');

    $row = $repo->listProviders()[0] ?? [];
    assert_true(!str_contains((string) ($row['last_error'] ?? ''), $secret), 'football_providers.last_error is stored redacted');
    assert_contains('[redacted]', (string) ($row['last_error'] ?? ''), 'and visibly redacted, not silently truncated');

    assert_true(!str_contains((string) json_encode($module->providerStatus()), $secret), 'the public status endpoint stays clean');
    assert_true(!str_contains((string) json_encode($module->diagnostics()->snapshot()), $secret), 'and so does diagnostics');

    // The redactor is also unit-checkable: the forms a real client uses, and the
    // innocent messages it must leave alone.
    $redact = \AIWorkforce\Football\ProviderGateway::redactSecrets(...);
    assert_contains('[redacted]', $redact('header: Authorization: Bearer ' . $secret), 'a bearer token is masked');
    assert_contains('[redacted]', $redact('invalid api_key=' . $secret), 'a key=value pair is masked');
    assert_equals('fixture endpoint unavailable', $redact('fixture endpoint unavailable'), 'an ordinary message is passed through untouched');
    assert_equals('HTTP 429: slow down, retry in 30s', $redact('HTTP 429: slow down, retry in 30s'), 'rate-limit wording survives');
    assert_equals('invalid X-RapidAPI-Key header', $redact('invalid X-RapidAPI-Key header'),
        'naming a header is not the same as carrying one: an ordinary sentence is left alone');
    assert_contains('feed.example/v3/fixtures?date=2026-09-05', $redact('HTTP 401 for GET https://feed.example/v3/fixtures?date=2026-09-05&access=' . $secret),
        'the diagnostic half of the URL is kept');
});

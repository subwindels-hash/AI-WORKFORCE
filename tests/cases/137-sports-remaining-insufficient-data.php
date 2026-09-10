<?php
/**
 * The last INSUFFICIENT_DATA dead end of the 2026-09-10 runs — the one the
 * form-carry-forward fix could not reach in production.
 *
 * The daily engine reuses recentForm that an EARLIER run verified instead of
 * paying a provider lookup for it again (tests/cases/134). That only ever
 * worked against the in-memory test repository: the real repository's
 * findMatch() handed the stored `payload` back as the RAW JSON TEXT the column
 * holds, while every other row reader (findMatchById / listMatches) decodes it
 * first. `is_array($stored['payload'])` was therefore false on a real
 * database → "no stored form" on every run → the lookup budget and the
 * provider's daily quota were spent again on the fixtures the previous sweep
 * had already resolved, and everything past them stayed an
 * INSUFFICIENT_DATA rejection — for the rest of the day, because a
 * worldwide fixture pull (hundreds of leagues) never fits in the budget and
 * the quota does not refill until 00:00 UTC.
 *
 * Pinned here:
 *   • the repository contract — a match row's payload is the DECODED document
 *     for every reader, verified as a round trip against the real (throwaway
 *     sqlite) repository, which is the only way this class of bug shows up at
 *     all: an in-memory stub keeps arrays and hides it;
 *   • the engine's tolerance — a payload arriving as JSON text is still read,
 *     so a repository quirk can never silently discard verified evidence;
 *   • what is NOT relaxed — a reading past the form TTL, and a reading without
 *     a timestamp, stay honest INSUFFICIENT_DATA rejections after the decode;
 *   • a fixture whose provider row stated no season reaches the league table
 *     that exists for it — with the season its own endpoint requires for
 *     api-football, and with no season at all for the providers that read one
 *     as a range or an internal id (where inventing a year finds nothing).
 */
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\SportsRepository;
use AIWorkforce\Sports\ConfigurationService;
use AIWorkforce\Sports\ConfidenceEngine;
use AIWorkforce\Sports\CorrelationEngine;
use AIWorkforce\Sports\DailyTicketService;
use AIWorkforce\Sports\DataQualityEngine;
use AIWorkforce\Sports\DecisionRecorder;
use AIWorkforce\Sports\FeatureEngineeringEngine;
use AIWorkforce\Sports\FormResolver;
use AIWorkforce\Sports\MatchIntelligenceEngine;
use AIWorkforce\Sports\OddsFreshnessEngine;
use AIWorkforce\Sports\PredictionEngine;
use AIWorkforce\Sports\PredictionPipeline;
use AIWorkforce\Sports\Providers\ApiFootballProvider;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;
use AIWorkforce\Sports\Providers\TheSportsDbProvider;
use AIWorkforce\Sports\RiskEngine;
use AIWorkforce\Sports\SportsDataNormalizer;
use AIWorkforce\Sports\TicketGovernance;
use AIWorkforce\Sports\TicketOptimizer;
use AIWorkforce\Sports\ValueEngine;

function carry_audit(): AuditRepository
{
    return new class implements AuditRepository {
        public array $events = [];
        public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; }
        public function recent(int $l = 100): array { return []; }
    };
}

/**
 * The repository row shape a real storage engine produces: `payload` comes
 * back as the JSON TEXT in the column. Used to prove the daily engine reads
 * carried-forward form from either shape.
 */
class CarryDbShapedRepo extends SportsRepositoryStub
{
    public function findMatch(int $providerId, string $externalId): ?array
    {
        $row = parent::findMatch($providerId, $externalId);
        if ($row !== null && is_array($row['payload'] ?? null)) $row['payload'] = json_encode($row['payload']);
        return $row;
    }
}

/** Day of fixtures: 6 that cannot win a ticket, 2 that can, all in one league. */
function carry_provider(int &$standingsCalls): SportsDataProvider
{
    return new class($standingsCalls) implements SportsDataProvider {
        public function __construct(private int &$standingsCalls) {}
        public function id(): string { return 'carry-test'; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.95]; }
        public function fixtures(array $q): array
        {
            $out = [];
            for ($i = 0; $i < 6; $i++) {
                $out[] = [
                    'externalId' => 'soon' . $i, 'homeTeam' => 'Early' . $i, 'awayTeam' => 'Bird' . $i,
                    'competition' => 'Carry League', 'leagueId' => '1', 'season' => '2026',
                    'homeTeamId' => 'soonh' . $i, 'awayTeamId' => 'soona' . $i,
                    'kickoff' => gmdate('c', time() + 1800), 'status' => 'SCHEDULED', 'statusShort' => 'NS',
                ];
            }
            for ($i = 0; $i < 2; $i++) {
                $out[] = [
                    'externalId' => 'late' . $i, 'homeTeam' => 'Home' . $i, 'awayTeam' => 'Away' . $i,
                    'competition' => 'Carry League', 'leagueId' => '1', 'season' => '2026',
                    'homeTeamId' => 'lateh' . $i, 'awayTeamId' => 'latea' . $i,
                    'kickoff' => gmdate('c', time() + 86400), 'status' => 'SCHEDULED', 'statusShort' => 'NS',
                ];
            }
            return $out;
        }
        public function odds(string $e): array
        {
            return [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.9, 'observedAt' => gmdate('c')]];
        }
        public function results(string $e): array { return []; }
        public function standings(string $leagueId, string $season): array
        {
            $this->standingsCalls++;
            $rows = [];
            foreach (['lateh0', 'latea0', 'lateh1', 'latea1', 'soonh0', 'soona0'] as $team) {
                $rows[] = ['teamId' => $team, 'played' => 10, 'goalsFor' => 15, 'goalsAgainst' => 10];
            }
            return $rows;
        }
    };
}

/**
 * @return array{0:SportsRepository,1:DailyTicketService}
 */
function carry_stack(SportsRepository $repo, SportsDataProvider $provider, ?FormResolver $resolver = null): array
{
    $audit = carry_audit();
    $providers = new SportsProviderManager();
    $providers->register($provider);
    $config = new ConfigurationService($repo, $audit);
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    $service = new DailyTicketService(
        $repo, $audit, $providers, $config, new DataQualityEngine(), $pipeline,
        new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()),
        new DecisionRecorder($repo, $audit), $resolver
    );
    return [$repo, $service];
}

function carry_approve_calibration(SportsRepository $repo): void
{
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration(['model_version_id' => $modelId, 'method' => 'platt', 'intercept' => 0.0, 'slope' => 1.0, 'samples' => 30, 'ece' => 0.05, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. The repository contract, against the real database
// ═══════════════════════════════════════════════════════════════════════════

test('repository: saveMatch() returns the row a caller could re-read — decoded payload', function () {
    $repo = platform()->model->sports;
    $source = $repo->ensureProvider('carry-probe', 'Carry Probe');
    $providerId = (int) $source['id'];
    $externalId = 'carry-probe-' . substr(sha1((string) microtime(true)), 0, 8);
    $match = SportsDataNormalizer::fixture([
        'externalId' => $externalId, 'homeTeam' => 'Probe Home', 'awayTeam' => 'Probe Away',
        'competition' => 'Probe League', 'leagueId' => '7', 'season' => '2026',
        'homeTeamId' => 'p1', 'awayTeamId' => 'p2',
        'kickoff' => gmdate('c', time() + 86400), 'status' => 'SCHEDULED', 'statusShort' => 'NS',
        'context' => ['recentForm' => [
            'homeGoalsPerMatch' => 1.5, 'awayGoalsPerMatch' => 1.2,
            'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 1.1,
            'source' => 'carry-probe:standings', 'timestamp' => gmdate('c'),
        ]],
    ], 'carry-probe');

    $saved = $repo->saveMatch($providerId, $match);
    assert_true(is_array($saved['payload'] ?? null), 'the returned row carries the document, not the column text');
    assert_close(1.5, (float) $saved['payload']['context']['recentForm']['homeGoalsPerMatch'], 0.0001, 'the saved payload keeps the verified form');

    $byExternal = $repo->findMatch($providerId, $externalId);
    assert_true(is_array($byExternal['payload'] ?? null), 'findMatch() decodes payload like every other match reader');
    assert_close(1.1, (float) $byExternal['payload']['context']['recentForm']['awayConcededPerMatch'], 0.0001, 'the form survives the round trip');
    assert_equals('carry-probe:standings', $byExternal['payload']['context']['recentForm']['source'] ?? null, 'provenance survives');

    $byId = $repo->findMatchById((int) $byExternal['id']);
    assert_equals($byId['payload'], $byExternal['payload'], 'both readers hand back the same document shape');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. The daily engine reads carried-forward form from EITHER payload shape
// ═══════════════════════════════════════════════════════════════════════════

test('daily ticket: form stored as raw JSON text is still carried forward — no INSUFFICIENT_DATA, no new lookup', function () {
    $calls = 0;
    $provider = carry_provider($calls);
    // Run 1 pays ONE lookup (the league table) and stores verified form.
    $repo = new CarryDbShapedRepo();
    [$repo, $service] = carry_stack($repo, $provider, new FormResolver(1));
    carry_approve_calibration($repo);
    $first = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:carry-json:1:' . uniqid());
    assert_equals(2, (int) $first['diagnostics']['fixturesWithRecentForm'], 'the first run resolves form for both eligible fixtures');
    assert_equals(0, (int) ($first['rejectionSummary']['INSUFFICIENT_DATA'] ?? 0));
    assert_equals(1, $calls, 'one table request served the whole league');

    // Run 2 has NO lookup budget at all: everything it predicts must come
    // from the form run 1 verified — read straight out of the stored rows.
    $calls = 0;
    [$repo2, $service2] = carry_stack($repo, $provider, new FormResolver(0));
    $second = $service2->runDaily(gmdate('Y-m-d'), 'daily-ticket:carry-json:2:' . uniqid());
    assert_equals(0, $calls, 'the provider is not asked again');
    assert_equals(2, (int) $second['diagnostics']['fixturesWithCarriedForwardForm'], 'a JSON-text payload is read, not treated as "no stored form"');
    assert_equals(2, (int) $second['diagnostics']['fixturesWithRecentForm']);
    assert_equals(0, (int) ($second['rejectionSummary']['INSUFFICIENT_DATA'] ?? 0), 'the second run does not re-lose the day to INSUFFICIENT_DATA');
    assert_equals(2, (int) $second['diagnostics']['sufficientDataFixtures'], 'both fixtures reach the model on carried-forward form');
    // Same odds, same model version: the stored prediction of run 1 is REUSED
    // rather than duplicated — proof the model ran, not that it was skipped.
    assert_true((int) $second['diagnostics']['predictionsReused'] > 0, 'the carried-forward form reaches the prediction stage');
});

test('daily ticket: carried-forward readings keep their honesty rules after the decode', function () {
    $calls = 0;
    $provider = carry_provider($calls);
    $repo = new SportsRepositoryStub();
    [$repo, $service] = carry_stack($repo, $provider, new FormResolver(1));
    carry_approve_calibration($repo);
    $first = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:carry-honesty:1:' . uniqid());
    assert_equals(2, (int) $first['diagnostics']['fixturesWithRecentForm']);

    // (a) form without a timestamp has no measurable age → never reused.
    foreach ($repo->matches as &$m) {
        if (isset($m['payload']['context']['recentForm'])) unset($m['payload']['context']['recentForm']['timestamp']);
    }
    unset($m);
    $calls = 0;
    [$repo2, $service2] = carry_stack($repo, $provider, new FormResolver(0));
    $unsigned = $service2->runDaily(gmdate('Y-m-d'), 'daily-ticket:carry-honesty:2:' . uniqid());
    assert_equals(0, (int) $unsigned['diagnostics']['fixturesWithCarriedForwardForm'], 'unverifiable form is never reused');
    assert_true((int) ($unsigned['rejectionSummary']['INSUFFICIENT_DATA'] ?? 0) > 0, 'and the fixture is rejected, not guessed');

    // (b) restore the stamp and age it past WINDELS_SPORTS_FORM_MAX_AGE.
    foreach ($repo->matches as &$m) {
        if (isset($m['payload']['context']['recentForm'])) {
            $m['payload']['context']['recentForm']['timestamp'] = gmdate('c', time() - 30 * 86400);
        }
    }
    unset($m);
    $stale = $service2->runDaily(gmdate('Y-m-d'), 'daily-ticket:carry-honesty:3:' . uniqid());
    assert_equals(0, (int) $stale['diagnostics']['fixturesWithCarriedForwardForm'], 'expired form is dropped, not extrapolated');
    assert_true((int) ($stale['rejectionSummary']['INSUFFICIENT_DATA'] ?? 0) > 0, 'expired form stays an explicit INSUFFICIENT_DATA rejection');
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. A fixture whose provider row stated no season still reaches a table
// ═══════════════════════════════════════════════════════════════════════════

test('api-football: a fixture without a stated season is asked with the year, never season=', function () {
    $urls = [];
    $year = (string) date('Y');
    $transport = function (string $url) use (&$urls, $year) {
        $urls[] = $url;
        // The provider's own rule: /standings needs a season. Answer the current
        // season's table and refuse anything else, exactly as the API does.
        if (str_contains($url, '/standings') && str_contains($url, '&season=' . $year)) {
            $entries = [];
            foreach (['77' => [19, 8], '78' => [11, 15]] as $id => $g) {
                $entries[] = ['team' => ['id' => $id, 'name' => 'Team ' . $id], 'rank' => count($entries) + 1,
                              'all' => ['played' => 10, 'goals' => ['for' => $g[0], 'against' => $g[1]]], 'points' => 21];
            }
            return ['status' => 200, 'body' => json_encode(['response' => [['league' => ['name' => 'Cup League', 'standings' => [$entries]]]]])];
        }
        return ['status' => 400, 'body' => json_encode(['errors' => ['season' => 'The season field is required.']])];
    };
    $provider = new ApiFootballProvider('k', 'https://api.test', 10, $transport);
    // What mapFixtures() produces when `league.season` is null (cups, friendlies).
    $fixture = ['externalId' => 'x1', 'homeTeamId' => '77', 'awayTeamId' => '78', 'leagueId' => '11', 'season' => ''];

    $resolver = new FormResolver(30);
    $enriched = $resolver->enrich($provider, [$fixture]);

    assert_true(!empty($enriched[0]['context']['recentForm']), 'the league table resolves the form');
    assert_close(1.9, (float) $enriched[0]['context']['recentForm']['homeGoalsPerMatch'], 0.0001, '19 goals / 10 played');
    assert_equals(0, (int) $resolver->stats()['lookupFailures'], 'the table request is answered, not refused');
    foreach ($urls as $url) {
        assert_false(str_contains($url, 'season=&') || str_ends_with($url, 'season='), 'no request carries an empty season: ' . $url);
    }
});

test('thesportsdb: no season means the current table — a bare year is never invented for it', function () {
    $urls = [];
    $transport = function (string $url) use (&$urls) {
        $urls[] = $url;
        // TheSportsDB reads its season as a `2025-2026` RANGE: a bare year
        // matches no table there, so the provider must be asked with no season
        // at all (which is how it serves the current table).
        if (str_contains($url, '/lookuptable.php') && !str_contains($url, '&s=')) {
            return ['status' => 200, 'body' => json_encode(['table' => [
                ['idTeam' => '133604', 'strTeam' => 'Rovers', 'intPlayed' => 8, 'intGoalsFor' => 12, 'intGoalsAgainst' => 9],
                ['idTeam' => '133605', 'strTeam' => 'City', 'intPlayed' => 8, 'intGoalsFor' => 7, 'intGoalsAgainst' => 14],
            ]])];
        }
        return ['status' => 200, 'body' => json_encode(['table' => null])];
    };
    $provider = new TheSportsDbProvider('123', 'https://api.test', 10, $transport);
    $resolver = new FormResolver(30);
    // Same league, one row with an empty season and one with no season key at
    // all: both mean "unstated", and a blanket year default would turn either
    // into `&s=2026` — a season TheSportsDB has no table for.
    $withEmpty = ['externalId' => 'e1', 'homeTeamId' => '133604', 'awayTeamId' => '133605', 'leagueId' => '4328', 'season' => ''];
    $withoutKey = ['externalId' => 'e2', 'homeTeamId' => '133604', 'awayTeamId' => '133605', 'leagueId' => '4328'];

    $enriched = $resolver->enrich($provider, [$withEmpty, $withoutKey]);

    foreach ($enriched as $i => $row) {
        assert_true(!empty($row['context']['recentForm']), 'the current table serves the form (fixture ' . $i . ')');
        assert_close(1.5, (float) $row['context']['recentForm']['homeGoalsPerMatch'], 0.0001, '12 goals / 8 played');
    }
    assert_equals(1, count($urls), 'one table request for both fixtures, with no season parameter');
    assert_false(str_contains($urls[0], '&s='), 'no invented year is sent to a provider that reads ranges');
});

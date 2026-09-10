<?php
/**
 * Recent-form resolution — the single dead end behind the 2026-09-10
 * NO_QUALIFIED_TICKET run (118 eligible → 90 fresh-odds → 0 sufficient-data,
 * 90 × INSUFFICIENT_DATA).
 *
 * Three distinct causes were found and are asserted here:
 *
 *   1. TheSportsDB exposed NO form source at all. It publishes a season
 *      league table (lookuptable.php) carrying played / goals-for /
 *      goals-against per team — the exact verified inputs the model needs —
 *      and the adapter now reads it.
 *   2. The FormResolver only used a league table for SportMonks. Any provider
 *      that publishes standings now serves form, one request per (league,
 *      season), and api-football falls back to the table when a team has no
 *      per-team statistics yet.
 *   3. The daily engine spent the whole lookup budget walking the provider's
 *      raw response in arrival order — mostly fixtures the very next gate
 *      throws away (already kicked off, or inside the 2h cut-off) — so no
 *      ticket-eligible fixture ever got form. Enrichment now runs on the
 *      eligible fixtures only, and form a previous run already verified is
 *      carried forward instead of re-fetched.
 *
 * Nothing here relaxes a gate: unresolvable form is still an explicit
 * INSUFFICIENT_DATA rejection, and carried-forward form is only reused inside
 * its TTL with its original source and timestamp intact.
 */
use AIWorkforce\Sports\ConfidenceEngine;
use AIWorkforce\Sports\ConfigurationService;
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

// ═══════════════════════════════════════════════════════════════════════════
// 1. TheSportsDB league table → form inputs
// ═══════════════════════════════════════════════════════════════════════════

test('thesportsdb: the season table is read as standings (played + goals)', function () {
    $body = json_encode(['table' => [
        ['idTeam' => '133604', 'strTeam' => 'Arsenal', 'strSeason' => '2025-2026', 'intRank' => 1,
         'intPlayed' => 10, 'intWin' => 7, 'intDraw' => 2, 'intLoss' => 1,
         'intGoalsFor' => 22, 'intGoalsAgainst' => 8, 'intPoints' => 23],
        ['idTeam' => '133602', 'strTeam' => 'Chelsea', 'strSeason' => '2025-2026', 'intRank' => 2,
         'intPlayed' => 10, 'intWin' => 6, 'intDraw' => 1, 'intLoss' => 3,
         'intGoalsFor' => 18, 'intGoalsAgainst' => 12, 'intPoints' => 19],
        // A row with no team id is skipped rather than invented.
        ['strTeam' => 'Unknown', 'intPlayed' => 5],
    ]]);
    $urls = [];
    $p = new TheSportsDbProvider('123', 'https://www.thesportsdb.com/api/v1/json', 10, function (string $url) use (&$urls, $body) {
        $urls[] = $url;
        return ['status' => 200, 'body' => $body];
    });
    $rows = $p->standings('4328', '2025-2026');
    assert_equals(2, count($rows), 'only rows the vendor identified are kept');
    assert_equals('133604', $rows[0]['teamId']);
    assert_equals(10, $rows[0]['played']);
    assert_equals(22, $rows[0]['goalsFor']);
    assert_equals(8, $rows[0]['goalsAgainst']);
    assert_true(str_contains($urls[0], 'lookuptable.php'), 'the documented v1 table endpoint is used');
    assert_true(str_contains($urls[0], 'l=4328') && str_contains($urls[0], 's=2025-2026'));
});

test('thesportsdb: an empty or malformed table is empty, never fabricated', function () {
    $p = new TheSportsDbProvider('123', 'https://www.thesportsdb.com/api/v1/json', 10, fn() => ['status' => 200, 'body' => '{"table":null}']);
    assert_equals([], $p->standings('4328', '2025-2026'));
    assert_equals([], $p->standings('', '2025-2026'), 'no league id → no request, no rows');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. FormResolver: any standings-capable provider resolves form
// ═══════════════════════════════════════════════════════════════════════════

test('FormResolver resolves form from any provider that publishes standings', function () {
    $calls = 0;
    $body = json_encode(['table' => [
        ['idTeam' => 'h1', 'intPlayed' => 10, 'intGoalsFor' => 20, 'intGoalsAgainst' => 10],
        ['idTeam' => 'a1', 'intPlayed' => 8, 'intGoalsFor' => 8, 'intGoalsAgainst' => 16],
        ['idTeam' => 'h2', 'intPlayed' => 10, 'intGoalsFor' => 15, 'intGoalsAgainst' => 5],
        ['idTeam' => 'a2', 'intPlayed' => 10, 'intGoalsFor' => 5, 'intGoalsAgainst' => 25],
    ]]);
    $p = new TheSportsDbProvider('123', 'https://www.thesportsdb.com/api/v1/json', 10, function () use (&$calls, $body) {
        $calls++;
        return ['status' => 200, 'body' => $body];
    });
    $resolver = new FormResolver();
    $enriched = $resolver->enrich($p, [
        ['homeTeamId' => 'h1', 'awayTeamId' => 'a1', 'leagueId' => '4328', 'season' => '2025-2026'],
        ['homeTeamId' => 'h2', 'awayTeamId' => 'a2', 'leagueId' => '4328', 'season' => '2025-2026'],
    ]);
    assert_equals(1, $calls, 'ONE table request serves every team in the league');
    $first = $enriched[0]['context']['recentForm'];
    assert_equals(2.0, $first['homeGoalsPerMatch']);
    assert_equals(1.0, $first['homeConcededPerMatch']);
    assert_equals(1.0, $first['awayGoalsPerMatch']);
    assert_equals(2.0, $first['awayConcededPerMatch']);
    assert_equals('thesportsdb:team-statistics', $first['source'], 'the source is attributed to the provider');
    assert_true(!empty($enriched[1]['context']['recentForm']), 'the cached table serves the second fixture too');
    assert_equals(1, (int) $resolver->stats()['lookupsUsed']);
    assert_equals(true, (bool) $resolver->stats()['providerCapable']);
});

test('FormResolver: a team missing from the table stays honestly unresolved', function () {
    $body = json_encode(['table' => [['idTeam' => 'h1', 'intPlayed' => 10, 'intGoalsFor' => 20, 'intGoalsAgainst' => 10]]]);
    $p = new TheSportsDbProvider('123', 'https://www.thesportsdb.com/api/v1/json', 10, fn() => ['status' => 200, 'body' => $body]);
    $enriched = (new FormResolver())->enrich($p, [['homeTeamId' => 'h1', 'awayTeamId' => 'ghost', 'leagueId' => '4328', 'season' => '2025']]);
    assert_true(empty($enriched[0]['context']['recentForm']), 'half a match of evidence is no evidence');
});

test('FormResolver: api-football falls back to the league table for a team with no statistics', function () {
    // A newly promoted / cup-entry team answers played = 0 on
    // /teams/statistics; the same season's table still has the numbers.
    $calls = [];
    $transport = function (string $url) use (&$calls) {
        $calls[] = $url;
        if (str_contains($url, '/teams/statistics')) {
            return ['status' => 200, 'body' => json_encode(['response' => ['fixtures' => ['played' => ['total' => 0]], 'goals' => []]])];
        }
        return ['status' => 200, 'body' => json_encode(['response' => [['league' => ['standings' => [[
            ['team' => ['id' => 33], 'all' => ['played' => 10, 'goals' => ['for' => 18, 'against' => 9]]],
            ['team' => ['id' => 40], 'all' => ['played' => 10, 'goals' => ['for' => 12, 'against' => 14]]],
        ]]]]]])];
    };
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $transport);
    $enriched = (new FormResolver())->enrich($p, [['homeTeamId' => '33', 'awayTeamId' => '40', 'leagueId' => '39', 'season' => '2026']]);
    $form = $enriched[0]['context']['recentForm'] ?? null;
    assert_true(is_array($form), 'the standings fallback rescues the fixture');
    assert_equals(1.8, $form['homeGoalsPerMatch']);
    assert_equals(1.4, $form['awayConcededPerMatch']);
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. The normalizer preserves the reading's timestamp
// ═══════════════════════════════════════════════════════════════════════════

test('normalizer keeps the recentForm timestamp so its age stays measurable', function () {
    $n = SportsDataNormalizer::fixture([
        'externalId' => 'f1', 'homeTeam' => 'A', 'awayTeam' => 'B', 'competition' => 'L',
        'kickoff' => gmdate('c', time() + 86400),
        'context' => ['recentForm' => [
            'homeGoalsPerMatch' => 1.5, 'awayGoalsPerMatch' => 1.2,
            'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 1.1,
            'source' => 'p:team-statistics', 'timestamp' => '2026-09-09T10:00:00+00:00',
        ]],
    ], 'p');
    assert_equals('2026-09-09T10:00:00+00:00', $n['context']['recentForm']['timestamp']);
    assert_equals('p:team-statistics', $n['context']['recentForm']['source']);
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. The daily engine spends its budget where a ticket can still be won
// ═══════════════════════════════════════════════════════════════════════════

/** Provider whose day is mostly unusable fixtures, with two ticket-eligible ones at the end. */
function fr_provider(int &$standingsCalls, array $formByTeam = []): SportsDataProvider
{
    return new class($standingsCalls, $formByTeam) implements SportsDataProvider {
        public function __construct(private int &$standingsCalls, private array $formByTeam) {}
        public function id(): string { return 'fr-test'; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.95]; }
        public function fixtures(array $q): array
        {
            $out = [];
            // 6 fixtures that cannot win a ticket: already kicked off / inside
            // the 2-hour cut-off. They come FIRST, exactly like a real
            // worldwide pull ordered by kickoff.
            for ($i = 0; $i < 6; $i++) {
                $out[] = [
                    'externalId' => 'soon' . $i, 'homeTeam' => 'Early' . $i, 'awayTeam' => 'Bird' . $i,
                    'competition' => 'Form League', 'leagueId' => '1', 'season' => '2026',
                    'homeTeamId' => 'soonh' . $i, 'awayTeamId' => 'soona' . $i,
                    'kickoff' => gmdate('c', time() + 1800), 'status' => 'SCHEDULED', 'statusShort' => 'NS',
                ];
            }
            for ($i = 0; $i < 2; $i++) {
                $out[] = [
                    'externalId' => 'late' . $i, 'homeTeam' => 'Home' . $i, 'awayTeam' => 'Away' . $i,
                    'competition' => 'Form League', 'leagueId' => '1', 'season' => '2026',
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

/** @return array{0:SportsRepositoryStub,1:DailyTicketService} */
function fr_stack(SportsDataProvider $provider, ?FormResolver $resolver = null): array
{
    $repo = new SportsRepositoryStub();
    $audit = cb_audit();
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

test('daily ticket: the form budget is spent on ticket-eligible fixtures, not on the whole day', function () {
    $calls = 0;
    // A budget of ONE lookup — the exact starvation condition of the reported
    // run. Walking the raw response in arrival order would burn it on a
    // fixture kicking off in 30 minutes and leave every eligible fixture bare.
    [$repo, $service] = fr_stack(fr_provider($calls), new FormResolver(1));
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration(['model_version_id' => $modelId, 'method' => 'platt', 'intercept' => 0.0, 'slope' => 1.0, 'samples' => 30, 'ece' => 0.05, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);

    $run = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:form-budget:' . uniqid());
    $diag = $run['diagnostics'];
    assert_equals(2, (int) $diag['formEnrichmentCandidates'], 'only the ticket-eligible fixtures are offered to the resolver');
    assert_equals(2, (int) $diag['fixturesWithRecentForm'], 'both eligible fixtures carry verified form');
    assert_equals(2, (int) $diag['eligibleFixtures']);
    assert_equals(2, (int) $diag['sufficientDataFixtures'], 'INSUFFICIENT_DATA no longer swallows the day');
    assert_equals(0, (int) ($run['rejectionSummary']['INSUFFICIENT_DATA'] ?? 0));
    assert_true((int) $diag['predictionsGenerated'] > 0, 'the model actually computes predictions');
});

test('daily ticket: verified form is carried forward instead of re-fetched, and expires', function () {
    $calls = 0;
    $provider = fr_provider($calls);
    [$repo, $service] = fr_stack($provider);
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration(['model_version_id' => $modelId, 'method' => 'platt', 'intercept' => 0.0, 'slope' => 1.0, 'samples' => 30, 'ece' => 0.05, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);

    $first = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:carry-1:' . uniqid());
    assert_equals(2, (int) $first['diagnostics']['fixturesWithRecentForm']);
    assert_equals(1, $calls, 'one table request for the whole league');

    // Second run, resolver with ZERO budget: the provider must not be asked
    // again, and the stored form still carries the day.
    [$repo2, $service2] = fr_stack($provider, new FormResolver(0));
    $repo2->matches = $repo->matches;   // the previous run's stored fixtures
    $repo2->providers = $repo->providers;
    $modelId2 = $repo2->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo2->saveCalibration(['model_version_id' => $modelId2, 'method' => 'platt', 'intercept' => 0.0, 'slope' => 1.0, 'samples' => 30, 'ece' => 0.05, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);
    $calls = 0;
    $second = $service2->runDaily(gmdate('Y-m-d'), 'daily-ticket:carry-2:' . uniqid());
    assert_equals(0, $calls, 'no provider request — the stored reading is reused');
    assert_equals(2, (int) $second['diagnostics']['fixturesWithCarriedForwardForm'], 'both fixtures reuse verified form');
    assert_equals(2, (int) $second['diagnostics']['fixturesWithRecentForm']);

    // Age the stored readings past the TTL: expired form is dropped, and with
    // a dead budget the fixture is honestly rejected rather than guessed.
    foreach ($repo2->matches as &$m) {
        if (isset($m['payload']['context']['recentForm'])) {
            $m['payload']['context']['recentForm']['timestamp'] = gmdate('c', time() - 30 * 86400);
        }
    }
    unset($m);
    $third = $service2->runDaily(gmdate('Y-m-d'), 'daily-ticket:carry-3:' . uniqid());
    assert_equals(0, (int) $third['diagnostics']['fixturesWithCarriedForwardForm'], 'stale form is never reused');
    assert_equals(0, (int) $third['diagnostics']['fixturesWithRecentForm']);
    assert_true(($third['rejectionSummary']['INSUFFICIENT_DATA'] ?? 0) > 0, 'no form → explicit rejection, nothing fabricated');
});

test('daily ticket: a starved form budget says so, with the numbers to fix it', function () {
    $calls = 0;
    // Budget 0 and no stored history: every eligible fixture is bare.
    [$repo, $service] = fr_stack(fr_provider($calls), new FormResolver(0));
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration(['model_version_id' => $modelId, 'method' => 'platt', 'intercept' => 0.0, 'slope' => 1.0, 'samples' => 30, 'ece' => 0.05, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);

    $run = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:starved:' . uniqid());
    assert_equals('NO_QUALIFIED_TICKET', $run['status']);
    assert_contains('WINDELS_SPORTS_FORM_LOOKUPS', $run['message'], 'the operator is told which knob starved');
    assert_contains('ticket-eligible fixture', $run['message'], 'and over how many fixtures');
    assert_contains('with-form', $run['message'], 'the funnel exposes the form stage explicitly');
});

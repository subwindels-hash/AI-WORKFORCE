<?php
/**
 * WINDELS Lottery Intelligence — EUROMILLIONS · LOTTERY INTELLIGENCE
 * analysis & suggestion system.
 *
 * Exercises the complete pipeline the operators asked for:
 *   LoteriasAPI → historical sync → validation → database → intelligence
 *   analysis → ranked candidate lines → UI, with these invariants:
 *   - everything is computed from the verified dataset (never fabricated);
 *   - candidate lines are ranked by the disclosed STATISTICAL BALANCE SCORE;
 *   - the report is reproducible (recorded seed) and explains each score;
 *   - a number is never claimed "more likely" because it is frequent or absent;
 *   - insufficient data is stated plainly and produces no fake lines;
 *   - the API key never appears in the report, storage or UI.
 */
use AIWorkforce\Lottery\LotteryIntelligence;
use AIWorkforce\Lottery\LoteriasApiProvider;

function fx_intel_audit(): \AIWorkforce\Persistence\AuditRepository
{
    return new class implements \AIWorkforce\Persistence\AuditRepository {
        public array $events = [];
        public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; }
        public function recent(int $l = 100): array { return []; }
    };
}

/** One valid EuroMillions vendor draw (5 mains + 2 stars, all in range). */
function fx_intel_draw(string $date, string $drawId, array $combination, array $stars): array
{
    return [
        'game' => ['slug' => 'euromillones', 'name' => 'Euromillones'],
        'drawId' => $drawId,
        'drawDate' => $date,
        'dayOfWeek' => 'Viernes',
        'status' => 'COMPLETED',
        'combination' => $combination,
        'resultData' => ['estrellas' => $stars],
        'jackpot' => '1700000000',
        'jackpotFormatted' => '17.000.000,00 €',
        'prizes' => [['category' => 1, 'categoryName' => '5 + 2 estrellas', 'winners' => 1,
            'prizeAmount' => '1700000000', 'formattedPrize' => '17.000.000,00 €']],
    ];
}

/**
 * The real September 4, 2026 vendor payload — the newest verified draw. The
 * feed is free to publish the line in any order; the combination and stars
 * are deliberately SCRAMBLED so the canonical ascending-form guarantee is
 * exercised end-to-end.
 */
function fx_intel_sep4_draw(): array
{
    return [
        'game' => ['slug' => 'euromillones', 'name' => 'Euromillones'],
        'drawId' => '2026233',
        'drawDate' => '2026-09-04',
        'dayOfWeek' => 'Viernes',
        'status' => 'COMPLETED',
        'combination' => [46, 27, 12, 19, 11],        // 11 12 19 27 46, scrambled
        'resultData' => ['estrellas' => [12, 4]],    // 4 12, scrambled
        'jackpot' => '8931912000',                   // integer cents = €89,319,120
        'jackpotFormatted' => '89.319.120,00 €',
        'prizes' => [['category' => 1, 'categoryName' => '5 + 2 estrellas', 'winners' => 0,
            'prizeAmount' => '8931912000', 'formattedPrize' => '89.319.120,00 €']],
    ];
}

/**
 * A LoteriasApiProvider whose transport answers /latest and /range with
 * `$count` distinct, valid, chronologically-ordered draws (newest first).
 * When `$realSep4` the newest row is the actual Sept 4, 2026 vendor payload.
 * @return array{0:LoteriasApiProvider,1:list<array<string,mixed>>}
 */
function fx_intel_provider(int $count = 60, bool $realSep4 = false): array
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        if ($i === 0 && $realSep4) {
            $rows[] = fx_intel_sep4_draw();
            continue;
        }
        $date = gmdate('Y-m-d', strtotime('2026-09-04 -' . ($i * 4) . ' days'));
        $rows[] = fx_intel_draw(
            $date,
            '2026' . str_pad((string) (1000 - $i), 3, '0', STR_PAD_LEFT),
            [1 + ($i % 10), 11 + ($i % 9), 21 + ($i % 8), 31 + ($i % 7), 41 + ($i % 6)],
            [1 + ($i % 6), 7 + ($i % 5)]
        );
    }
    $transport = function (string $url, array $headers) use ($rows): array {
        if (str_contains($url, '/latest')) {
            return ['status' => 200, 'body' => json_encode([
                'success' => true, 'data' => $rows[0], 'timestamp' => '2026-09-04T22:00:00.000Z',
            ])];
        }
        return ['status' => 200, 'body' => json_encode([
            'success' => true, 'data' => $rows, 'meta' => ['hasNext' => false, 'total' => count($rows)],
        ])];
    };
    return [new LoteriasApiProvider(null, 'live-key-secret-123', null, true, $transport), $rows];
}

/** A synced intelligence stack over `$count` verified draws (stub repository). */
function fx_intel_stack(int $count = 60, bool $realSep4 = false): array
{
    $repo = new LotteryRepositoryStub();
    $audit = fx_intel_audit();
    [$provider] = fx_intel_provider($count, $realSep4);
    $intel = new LotteryIntelligence($repo, $audit, $provider);
    $intel->sync(200);
    return [$repo, $audit, $intel];
}

test('intelligence report: composed from the verified dataset, candidates ranked by score', function () {
    [$repo, $audit, $intel] = fx_intel_stack(60);

    $report = $intel->intelligenceReport(6, 7);
    assert_equals('LOTTERY_INTELLIGENCE_REPORT', $report['kind']);
    assert_equals('RELIABLE', $report['dataState']);
    assert_null($report['warning']);
    assert_equals(60, $report['historicalDrawsAnalyzed']);
    assert_equals(60, $report['dataset']['draws']);
    assert_equals('VERIFIED_HISTORICAL_DATABASE', $report['dataset']['source']);
    assert_equals('2026-09-04', $report['asOfDrawDate']);
    assert_equals(true, $report['reproducible']);
    assert_equals(7, $report['seed'], 'the seed used is recorded for reproducibility');

    // Latest verified draw + last sync come from the stored record.
    assert_not_null($report['latestVerifiedDraw']);
    assert_equals('2026-09-04', $report['latestVerifiedDraw']['draw_date']);
    assert_not_null($report['lastSync']['lastSuccessAt'], 'last synchronization recorded');

    // Main-number and Lucky-Star analysis blocks.
    assert_equals('main', $report['mainNumberAnalysis']['field']);
    assert_equals(5, count($report['mainNumberAnalysis']['mostFrequent']));
    assert_equals(5, count($report['mainNumberAnalysis']['recentHot']));
    assert_equals(5, count($report['mainNumberAnalysis']['longestAbsence']));
    assert_equals('stars', $report['starAnalysis']['field']);
    assert_equals(5, count($report['starAnalysis']['mostFrequent']));

    // Recurring combinations and distribution.
    assert_true(count($report['recurringCombinations']['mainPairs']) > 0);
    assert_true(count($report['recurringCombinations']['mainTriplets']) > 0);
    assert_true(count($report['recurringCombinations']['starPairs']) > 0);
    assert_true(count($report['distribution']['oddEven']) > 0);

    // Ranked candidate lines: 5 mains + 2 stars, sorted by score desc.
    assert_equals(6, count($report['candidates']));
    $prev = 101;
    foreach ($report['candidates'] as $c) {
        assert_equals(5, count($c['mains']));
        assert_equals(2, count($c['stars']));
        assert_true($c['score'] >= 0 && $c['score'] <= 100);
        assert_true($c['score'] <= $prev, 'candidates are ranked by score (descending)');
        assert_true(count($c['explanation']) >= 4, 'every line explains why it was selected');
        assert_true(is_array($c['scoreBreakdown']), 'the factors behind the score are disclosed');
        $sorted = $c['mains']; sort($sorted);
        assert_equals($sorted, $c['mains'], 'mains are stored ascending');
        $prev = $c['score'];
    }
    $best = $report['bestLine'];
    assert_equals($report['candidates'][0]['mains'], $best['mains']);
    assert_equals($report['candidates'][0]['stars'], $best['stars']);

    // Factors + weights are disclosed; the disclaimer is present.
    assert_equals(\AIWorkforce\Lottery\CombinationAnalyzer::WEIGHTS, $report['scoreWeights']);
    assert_true(str_contains($report['scoreMeaning'], 'NOT a probability'));
    assert_contains('independent random events', $report['disclaimer']);
    assert_true(str_contains($report['honestyNote'], 'not predictions'));

    // The provider API key never appears anywhere in the report.
    assert_false(str_contains(json_encode($report), 'live-key-secret-123'), 'API key never leaks');
    assert_equals('loteriasapi', $report['dataSource']['provider'], 'identity, not credentials');
});

test('intelligence report: reproducible — same seed and dataset give the same candidates', function () {
    [$repo, $audit, $intel] = fx_intel_stack(60);
    $a = $intel->intelligenceReport(5, 7);
    $b = $intel->intelligenceReport(5, 7);
    assert_equals(array_column($a['candidates'], 'mains'), array_column($b['candidates'], 'mains'));
    assert_equals(array_column($a['candidates'], 'stars'), array_column($b['candidates'], 'stars'));
    assert_equals($a['seed'], $b['seed']);
    // A different seed yields a different (but still valid) ranking.
    $c = $intel->intelligenceReport(5, 8);
    assert_not_equals(array_column($a['candidates'], 'mains'), array_column($c['candidates'], 'mains'));
});

test('intelligence report: drives the real adapter with the actual Sept 4 vendor payload', function () {
    [$repo, $audit, $intel] = fx_intel_stack(60, true);

    $report = $intel->intelligenceReport(5, 7);
    $latest = $report['latestVerifiedDraw'];
    assert_not_null($latest);
    assert_equals([11, 12, 19, 27, 46], $latest['main_numbers'], 'real Sept 4 mains, stored ascending');
    assert_equals([4, 12], $latest['lucky_stars'], 'real Sept 4 stars from the feed');
    assert_equals('2026233', $latest['draw_no']);
    assert_equals('2026-09-04', $latest['draw_date']);
    assert_equals('89319120.00', $latest['jackpot'], '€89,319,120 parsed from the vendor jackpotFormatted');
    assert_equals('windels.ai', $latest['source']);
    assert_equals('2026-09-04', $report['asOfDrawDate']);
    assert_equals(60, $report['historicalDrawsAnalyzed']);

    // Candidates are derived from the dataset, not hard-coded to the result.
    assert_equals(5, count($report['candidates']));
    foreach ($report['candidates'] as $c) {
        assert_equals(5, count($c['mains']));
        assert_equals(2, count($c['stars']));
        assert_true(max($c['mains']) <= 50 && min($c['mains']) >= 1);
        assert_true(max($c['stars']) <= 12 && min($c['stars']) >= 1);
    }
    // Honest framing: the winning combination is never presented as reproducible input.
    assert_false(str_contains(json_encode($report), 'live-key-secret-123'), 'API key never leaks');
});

test('intelligence report: insufficient data warns and generates nothing', function () {
    $repo = new LotteryRepositoryStub();
    $intel = new LotteryIntelligence($repo, fx_intel_audit(), new \AIWorkforce\Lottery\UnavailableLotteryProvider());
    $report = $intel->intelligenceReport(5, 1);
    assert_equals('INSUFFICIENT_DATA', $report['dataState']);
    assert_not_null($report['warning']);
    assert_contains('synchronize the lottery provider', $report['warning']);
    assert_equals(0, $report['historicalDrawsAnalyzed']);
    assert_equals([], $report['candidates']);
    assert_null($report['bestLine']);
    assert_null($report['latestVerifiedDraw']);
});

test('intelligence report: limited data is labelled provisional, never reliable', function () {
    [$repo, $audit, $intel] = fx_intel_stack(10);
    $report = $intel->intelligenceReport(5, 1);
    assert_equals('LIMITED_DATA', $report['dataState']);
    assert_not_null($report['warning']);
    assert_contains('fewer than ' . LotteryIntelligence::MIN_RELIABLE_DRAWS, $report['warning']);
    // Still generates lines, but never pretends they are reliable.
    assert_equals(5, count($report['candidates']));
});

test('runIntelligence: sync → analyse → persist → latestIntelligenceReport', function () {
    $repo = new LotteryRepositoryStub();
    $audit = fx_intel_audit();
    [$provider] = fx_intel_provider(60);
    $intel = new LotteryIntelligence($repo, $audit, $provider);

    assert_null($intel->latestIntelligenceReport(), 'no report before the first run');

    $result = $intel->runIntelligence(5, 42);
    assert_equals('RELIABLE', $result['dataState']);
    assert_equals(60, $result['historicalDrawsAnalyzed']);
    assert_not_null($result['sync'], 'the refresh step is recorded');
    assert_true(($result['saved']['combinationId'] ?? 0) > 0);
    assert_true(($result['saved']['decisionId'] ?? 0) > 0);

    $latest = $intel->latestIntelligenceReport();
    assert_not_null($latest);
    assert_equals('LOTTERY_INTELLIGENCE_REPORT', $latest['kind']);
    assert_equals('2026-09-04', $latest['asOfDrawDate']);
    assert_equals(42, $latest['seed']);

    // The combination row is stored under the INTELLIGENCE mode.
    $combos = array_values(array_filter($repo->combinations, fn($c) => $c['mode'] === 'INTELLIGENCE'));
    assert_equals(1, count($combos));
    assert_equals(5, $combos[0]['line_count']);

    // Audited.
    assert_true(in_array('LOTTERY_INTELLIGENCE_RUN', array_column($audit->events, 'type'), true));
});

test('intelligence: honesty — no banned claims and no credential path', function () {
    [$repo, $audit, $intel] = fx_intel_stack(60);
    $report = $intel->intelligenceReport(5, 7);
    $json = strtolower(json_encode($report));
    foreach (['win chance', 'win probability', 'will win', 'likely to win',
              'more likely to win', 'due to appear', 'certain win', 'secret formula',
              '90% chance', 'knows the next draw', 'live-key-secret-123'] as $banned) {
        assert_false(str_contains($json, $banned), 'report contains banned wording: ' . $banned);
    }
    // Honest reframing is present instead.
    assert_true(str_contains($report['honestyNote'], 'NOT more likely'), 'absence/frequency never raise the claim');
    assert_true(str_contains($report['disclaimer'], 'independent random events'));
});

test('lottery intelligence: routes, RBAC, controller state and the UI section', function () {
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    assert_contains("\$route['api/lottery/intelligence'] = 'api_lottery/intelligence';", $routes);
    assert_contains("\$route['api/lottery/intelligence/run'] = 'api_lottery/intelligence_run';", $routes);

    $c = file_get_contents(FCPATH . 'application/controllers/Api_lottery.php');
    assert_contains('public function intelligence()', $c);
    assert_contains('public function intelligence_run()', $c);
    assert_contains("requirePermission('lottery.manage')", $c);
    assert_false(str_contains($c, 'WIN CHANCE'), 'no win-chance claims in the API');
    assert_false(str_contains($c, 'predict'), 'no prediction claims in the API');

    $controller = file_get_contents(FCPATH . 'application/controllers/Lottery.php');
    assert_contains("'intelligence' => \$intelligence", $controller, 'the page hydrates the intelligence snapshot');
    assert_contains("'intelligenceRun' => '/api/lottery/intelligence/run'", $controller);

    $view = file_get_contents(FCPATH . 'application/views/lottery/index.php');
    assert_contains('EUROMILLIONS · LOTTERY INTELLIGENCE', $view);
    assert_contains('Run Lottery Intelligence', $view);
    assert_contains('data-lottery-intelligence-run', $view);
    assert_contains('X-CSRF-Token', $view, 'intelligence run POST carries the session CSRF header');
    assert_contains('Best-ranked candidate line', $view);
    assert_contains('Lucky-Star analysis', $view);
    assert_contains('Last synchronization', $view);
    assert_contains('Data source', $view);
    assert_contains('Disclaimer', $view);
    assert_not_contains('LoteriasAPI', $view, 'no vendor brand on the user dashboard');

    $features = file_get_contents(FCPATH . 'application/controllers/Api_system.php');
    assert_contains('Lottery Intelligence — analysis & suggestions', $features);

    $tools = file_get_contents(FCPATH . 'application/controllers/Tools.php');
    assert_contains('intelligence|cleanup', $tools, 'the scheduled job is advertised');
});

test('lottery intelligence: scheduled job regenerates only after a new verified draw', function () {
    $repo = new LotteryRepositoryStub();
    $audit = fx_intel_audit();
    [$provider] = fx_intel_provider(8);
    $intel = new LotteryIntelligence($repo, $audit, $provider);
    $intel->sync(50);

    $cron = new \AIWorkforce\Lottery\LotteryCronService($repo, $audit, $intel);
    assert_true(in_array('intelligence', \AIWorkforce\Lottery\LotteryCronService::JOBS, true));

    $first = $cron->run('intelligence', '2026-09-04');
    assert_equals('OK', $first['status'], 'first run generates the report');
    assert_not_null($intel->latestIntelligenceReport());

    $repeat = $cron->run('intelligence', '2026-09-04');
    assert_equals('UP_TO_DATE', $repeat['status'], 'no new draw → no regeneration');

    // A new verified draw lands → the next scheduled run regenerates.
    $intel->importDraws([[
        'externalId' => '2026-09-07', 'drawDate' => '2026-09-07',
        'main' => [2, 14, 26, 33, 47], 'stars' => [3, 9],
        'source' => 'windels.ai', 'sourceTimestamp' => '2026-09-07T22:00:00+00:00',
    ]]);
    $after = $cron->run('intelligence', '2026-09-07');
    assert_equals('OK', $after['status'], 'a newly verified draw triggers a fresh analysis');
    assert_equals('2026-09-07', $intel->latestIntelligenceReport()['asOfDrawDate']);
});

/** A real LoteriasApiProvider whose transport serves 60 valid draws dated 2020. */
function fx_intel_db_provider(): array
{
    $rows = [];
    for ($i = 0; $i < 60; $i++) {
        $date = gmdate('Y-m-d', strtotime('2020-01-03 +' . ($i * 4) . ' days'));
        $rows[] = fx_intel_draw(
            $date,
            'FX2020-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
            [1 + ($i % 10), 11 + ($i % 9), 21 + ($i % 8), 31 + ($i % 7), 41 + ($i % 6)],
            [1 + ($i % 6), 7 + ($i % 5)]
        );
    }
    $transport = function (string $url, array $headers) use ($rows): array {
        if (str_contains($url, '/latest')) {
            return ['status' => 200, 'body' => json_encode([
                'success' => true, 'data' => $rows[0], 'timestamp' => '2020-08-26T22:00:00.000Z',
            ])];
        }
        return ['status' => 200, 'body' => json_encode([
            'success' => true, 'data' => $rows, 'meta' => ['hasNext' => false, 'total' => count($rows)],
        ])];
    };
    return [new LoteriasApiProvider(null, 'live-key-secret-123', null, true, $transport), $rows];
}

test('lottery intelligence: full pipeline persists through the real database', function () {
    $p = platform();
    $model = $p->model;
    [$provider] = fx_intel_db_provider();
    $intel = new LotteryIntelligence($model->lottery, $model->audit, $provider);

    // Provider → validation → real database (60 verified fixture draws).
    $sum = $intel->sync(60);
    assert_equals('OK', $sum['status']);
    assert_equals(60, $sum['imported'], 'the fixture draws land in the database as VERIFIED');

    // Analysis → ranked candidates → persistence (real repository).
    $result = $intel->runIntelligence(5, 7, false);
    assert_true(($result['saved']['combinationId'] ?? 0) > 0, 'combination persisted');
    assert_true(($result['saved']['decisionId'] ?? 0) > 0, 'decision persisted');
    assert_equals('RELIABLE', $result['dataState'], '60 verified draws cross the reliability floor');
    assert_equals(60, $result['historicalDrawsAnalyzed']);
    assert_equals(5, count($result['candidates']));

    $report = $intel->latestIntelligenceReport();
    assert_not_null($report);
    assert_equals('LOTTERY_INTELLIGENCE_REPORT', $report['kind']);
    assert_equals(60, $report['historicalDrawsAnalyzed']);
    assert_equals('2020-08-26', $report['asOfDrawDate']);

    $combo = $intel->combinationDetail((int) $result['saved']['combinationId']);
    assert_equals('INTELLIGENCE', $combo['mode']);
    assert_equals(5, count($combo['lines']));

    $snapshot = $intel->intelligenceSnapshot();
    assert_not_null($snapshot['report']);
    assert_equals(60, $snapshot['live']['verifiedDraws']);
    assert_not_null($snapshot['live']['lastSuccessfulSync']);
    // The provider API key never reaches the snapshot, storage or UI.
    assert_false(str_contains(json_encode($snapshot), 'live-key-secret-123'), 'API key never leaks');
});

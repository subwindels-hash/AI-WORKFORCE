<?php
/**
 * WINDELS Lottery Intelligence — EuroMillions "Last Verified Draw" (spec §12).
 *
 * The September 4, 2026 EuroMillions draw is the newest verified draw:
 *   main numbers 11 – 12 – 19 – 27 – 46   (order-insensitive; stored ascending)
 *   Lucky Stars   4 and 12                (from the provider feed, never hardcoded)
 *   draw date     2026-09-04 (Friday)
 *   jackpot       €89,319,120 (rollover — nobody matched 5+2)
 *
 * These tests exercise the COMPLETE flow the operators asked to be verified —
 * provider payload → validation → historical database → Last Verified Draw →
 * Strategy Lab / Backtesting — and pin down the two properties that regress
 * most easily:
 *   1. number order never leaks through: a scrambled feed line is stored and
 *      displayed in ascending canonical form, and re-importing the same five
 *      numbers in a different order is an idempotent no-op, never a conflict;
 *   2. EuroMillions is ONE shared draw: the winning combination is the same
 *      for every participating country, so nothing models per-country numbers.
 */
use AIWorkforce\Lottery\LotteryIntelligence;
use AIWorkforce\Lottery\LoteriasApiProvider;

function fx_lastdraw_audit(): \AIWorkforce\Persistence\AuditRepository
{
    return new class implements \AIWorkforce\Persistence\AuditRepository {
        public array $events = [];
        public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; }
        public function recent(int $l = 100): array { return []; }
    };
}

/**
 * The real September 4, 2026 vendor payload. The feed is free to publish the
 * line in any order — `combination` and the stars are delivered SCRAMBLED on
 * purpose so the canonical-form guarantees are actually exercised.
 */
function fx_sep4_vendor_draw(array $over = []): array
{
    return array_merge([
        'game' => ['slug' => 'euromillones', 'name' => 'Euromillones'],
        'drawId' => '2026233',
        'drawDate' => '2026-09-04',
        'dayOfWeek' => 'Viernes',
        'year' => 2026,
        'status' => 'COMPLETED',
        'combination' => [46, 27, 12, 19, 11],       // 11 12 19 27 46, scrambled
        'resultData' => ['estrellas' => [12, 4]],   // 4 12, scrambled
        'jackpot' => '8931912000',                   // integer cents = €89,319,120
        'jackpotFormatted' => '89.319.120,00 €',
        'prizes' => [
            ['category' => 1, 'categoryName' => '5 + 2 estrellas', 'winners' => 0,
                'prizeAmount' => '8931912000', 'formattedPrize' => '89.319.120,00 €'],
            ['category' => 2, 'categoryName' => '5 + 1 estrella', 'winners' => 4,
                'prizeAmount' => '123456789', 'formattedPrize' => '1.234.567,89 €'],
        ],
    ], $over);
}

/** A LoteriasApiProvider whose transport answers /latest and /range from memory. */
function fx_lastdraw_provider(array $rows, array $overrides = []): array
{
    $calls = new ArrayObject();
    $transport = function (string $url, array $headers) use ($rows, $calls): array {
        $calls->append(['url' => $url, 'headers' => $headers]);
        if (str_contains($url, '/latest')) {
            return ['status' => 200, 'body' => json_encode([
                'success' => true, 'data' => $rows[0], 'timestamp' => '2026-09-04T22:00:00.000Z',
            ])];
        }
        return ['status' => 200, 'body' => json_encode([
            'success' => true, 'data' => $rows, 'meta' => ['hasNext' => false, 'total' => count($rows)],
        ])];
    };
    $provider = new LoteriasApiProvider(
        $overrides['base_url'] ?? null,
        $overrides['api_key'] ?? 'live-key-secret-123',
        $overrides['game'] ?? null,
        $overrides['enabled'] ?? true,
        $transport,
    );
    return [$provider, $calls];
}

/**
 * `$count` historical draws, newest first — the newest is the real Sept 4,
 * 2026 result; the rest are distinct, valid EuroMillions lines.
 */
function fx_lastdraw_history_provider(int $count = 12): array
{
    $rows = [fx_sep4_vendor_draw()];
    for ($i = 1; $i < $count; $i++) {
        $date = gmdate('Y-m-d', strtotime('2026-09-04 -' . ($i * 4) . ' days'));
        $draw = fx_sep4_vendor_draw([
            'drawId' => '2026' . str_pad((string) (233 - $i), 3, '0', STR_PAD_LEFT),
            'drawDate' => $date,
            'combination' => [1 + ($i % 10), 11 + ($i % 9), 21 + ($i % 8), 31 + ($i % 7), 41 + ($i % 6)],
            'resultData' => ['estrellas' => [1 + ($i % 6), 7 + ($i % 5)]],
            'jackpotFormatted' => '17.000.000,00 €',
            'jackpot' => '1700000000',
        ]);
        $rows[] = $draw;
    }
    return fx_lastdraw_provider($rows);
}

test('last verified draw: a scrambled feed line is stored in ascending canonical form', function () {
    $repo = new LotteryRepositoryStub();
    $intel = new LotteryIntelligence($repo, fx_lastdraw_audit(), new \AIWorkforce\Lottery\UnavailableLotteryProvider());
    $sum = $intel->importDraws([[
        'externalId' => '2026-09-04', 'drawDate' => '2026-09-04',
        'main' => [46, 27, 12, 19, 11], 'stars' => [12, 4],
        'jackpot' => '89319120.00', 'rollover' => true,
        'source' => 'loteriasapi.com (SELAE)', 'sourceTimestamp' => '2026-09-04T22:00:00+00:00',
    ]]);
    assert_equals(1, $sum['imported']);

    $draw = $repo->findDrawByExternal('EUROMILLIONS', '2026-09-04');
    assert_equals([11, 12, 19, 27, 46], $draw['payload']['main'], 'mains stored ascending');
    assert_equals([4, 12], $draw['payload']['stars'], 'stars stored ascending');

    // The normalized lottery_draw_numbers rows are ascending too (5 MAIN + 2 STAR).
    $numbers = array_values(array_filter($repo->listDrawNumbers((int) $draw['id']), fn($n) => $n['kind'] === 'MAIN'));
    assert_equals([11, 12, 19, 27, 46], array_column($numbers, 'number'), 'positional rows ascend');
});

test('last verified draw: re-importing the same numbers in another order is unchanged, not a conflict', function () {
    $repo = new LotteryRepositoryStub();
    $intel = new LotteryIntelligence($repo, fx_lastdraw_audit(), new \AIWorkforce\Lottery\UnavailableLotteryProvider());
    $intel->importDraws([[
        'externalId' => '2026-09-04', 'drawDate' => '2026-09-04',
        'main' => [46, 27, 12, 19, 11], 'stars' => [12, 4],
        'source' => 'loteriasapi.com (SELAE)', 'sourceTimestamp' => '2026-09-04T22:00:00+00:00',
    ]]);
    // Same draw, every number in a different position.
    $again = $intel->importDraws([[
        'externalId' => '2026-09-04', 'drawDate' => '2026-09-04',
        'main' => [11, 12, 19, 27, 46], 'stars' => [4, 12],
        'source' => 'loteriasapi.com (SELAE)', 'sourceTimestamp' => '2026-09-04T22:00:00+00:00',
    ]]);
    assert_equals(0, $again['imported']);
    assert_equals(1, $again['unchanged'], 'order-insensitive idempotency');
    assert_equals(0, $again['conflicts'], 'never a false conflict for a re-ordered set');
    assert_equals(1, $repo->countDraws('EUROMILLIONS'), 'still one draw row');
});

test('last verified draw: Sept 4, 2026 flows provider → validation → database → Last Verified Draw', function () {
    $repo = new LotteryRepositoryStub();
    [$provider] = fx_lastdraw_provider([fx_sep4_vendor_draw()]);
    $intel = new LotteryIntelligence($repo, fx_lastdraw_audit(), $provider);

    assert_null($intel->latestVerifiedDraw(), 'nothing verified before the first sync');
    assert_equals(0, $intel->verifiedDrawCount());

    $sync = $intel->sync(10);
    assert_equals('OK', $sync['status']);
    assert_equals(1, $sync['imported'], 'the Sept 4 draw is imported');
    assert_equals(0, $sync['failed'], 'a real vendor payload never fails validation');
    assert_equals(1, $intel->drawCount(), 'historical draw count goes 0 → 1');
    assert_equals(1, $intel->verifiedDrawCount());

    $last = $intel->latestVerifiedDraw();
    assert_not_null($last);
    assert_equals('2026-09-04', $last['draw_date'], 'draw date correct');
    assert_equals([11, 12, 19, 27, 46], $last['main_numbers'], 'five mains, ascending');
    assert_equals([11, 12, 19, 27, 46], $last['numbers']['main'], 'numbers.main is the canonical line');
    assert_equals([4, 12], $last['lucky_stars'], 'two Lucky Stars from the feed');
    assert_equals([4, 12], $last['numbers']['stars'], 'numbers.stars is the canonical line');
    assert_equals('VERIFIED', $last['verification_status'], 'verified only after successful validation');
    assert_equals('89319120.00', $last['jackpot'], 'jackpot stored from the feed (€89,319,120)');
    assert_equals(1, (int) $last['rollover'], 'a rollover draw is recorded');
    assert_true(str_contains((string) $last['source'], 'loteriasapi'), 'source recorded');
    assert_not_null($last['source_timestamp'], 'source timestamp recorded');
});

test('last verified draw: dashboard status surfaces the verified record, never a hardcoded copy', function () {
    $repo = new LotteryRepositoryStub();
    [$provider] = fx_lastdraw_provider([fx_sep4_vendor_draw()]);
    $intel = new LotteryIntelligence($repo, fx_lastdraw_audit(), $provider);
    $intel->sync(10);

    $status = $intel->status();
    assert_equals(1, $status['verifiedDraws']);
    assert_true($status['dataAvailable']);
    assert_not_null($status['lastDraw']);
    assert_equals('2026-09-04', $status['lastDraw']['draw_date']);
    assert_equals([11, 12, 19, 27, 46], $status['lastDraw']['numbers']['main']);
    assert_equals([4, 12], $status['lastDraw']['numbers']['stars']);
    assert_equals('89319120.00', $status['lastDraw']['jackpot']);

    // Every draw-list surface renders the same canonical line.
    $list = $intel->listDraws(5);
    assert_equals(1, count($list));
    assert_equals([11, 12, 19, 27, 46], $list[0]['numbers']['main']);
    assert_equals([4, 12], $list[0]['numbers']['stars']);
});

test('last verified draw: historical sync moves NEVER_SYNCED to a real timestamp and grows from 0', function () {
    $repo = new LotteryRepositoryStub();
    [$provider] = fx_lastdraw_history_provider(12);
    $intel = new LotteryIntelligence($repo, fx_lastdraw_audit(), $provider);

    $before = $intel->status();
    assert_equals(0, $before['verifiedDraws'], 'historical count starts at 0');
    assert_equals('NEVER_SYNCED', $before['syncStatus']);
    assert_null($before['lastSuccessfulSync']);

    $sync = $intel->sync(50);
    assert_equals('OK', $sync['status']);
    assert_equals(12, $sync['imported']);

    $after = $intel->status();
    assert_equals(12, $after['verifiedDraws'], 'historical draw count increased from 0');
    assert_equals('OK', $after['syncStatus'], 'NEVER_SYNCED became a successful sync');
    assert_not_null($after['lastSuccessfulSync'], 'the timestamp is the actual sync time');
    assert_true(str_contains((string) $after['lastSuccessfulSync'], 'T'), 'a real ISO timestamp');
    assert_equals(12, $after['historicalDataset']['draws']);
    assert_equals('2026-09-04', $after['historicalDataset']['to'], 'the dataset ends on the newest verified draw');
});

test('last verified draw: Strategy Lab and Backtesting read the same verified dataset', function () {
    $repo = new LotteryRepositoryStub();
    [$provider] = fx_lastdraw_history_provider(12);
    $intel = new LotteryIntelligence($repo, fx_lastdraw_audit(), $provider);
    $intel->sync(50);

    $dataset = $intel->historicalDataset();
    assert_equals(12, count($dataset));
    $newest = $dataset[count($dataset) - 1];
    assert_equals('2026-09-04', $newest['drawDate']);
    assert_equals([11, 12, 19, 27, 46], $newest['main'], 'Strategy Lab sees the same ascending line');
    assert_equals([4, 12], $newest['stars']);

    // Statistics name the dataset they used.
    $stats = $intel->statistics('frequency', 0);
    assert_equals('VERIFIED_HISTORICAL_DATABASE', $stats['dataset']['source']);
    assert_equals(12, $stats['dataset']['draws']);

    // Backtesting replays the same stored dataset (look-ahead safe, oldest first).
    $bt = $intel->backtest('HISTORICAL_FREQ', 1, 12);
    assert_equals(12, $bt['dataset']['draws']);
    assert_equals('2026-09-04', $bt['dataset']['to']);

    // Generate 5 AI lines from the historical dataset — not a random fallback.
    $report = $intel->generate(['mode' => 'HISTORICAL', 'count' => 5, 'seed' => 7]);
    assert_equals(5, count($report['lines']));
    assert_equals(12, $report['inputs']['drawsUsed']);
    assert_true($report['dataset']['usedForGeneration']);
});

test('last verified draw: an invalid Sept 4 line is never stored as verified', function () {
    $repo = new LotteryRepositoryStub();
    $audit = fx_lastdraw_audit();
    $bad = fx_sep4_vendor_draw(['combination' => [46, 27, 12, 19]]); // 4 mains only
    [$provider] = fx_lastdraw_provider([$bad]);
    $intel = new LotteryIntelligence($repo, $audit, $provider);

    $sync = $intel->sync(10);
    assert_equals(0, $sync['imported'], 'the malformed draw is rejected');
    assert_equals(1, $sync['failed']);
    assert_equals(0, $intel->drawCount());
    assert_equals(0, $intel->verifiedDrawCount());
    assert_null($intel->latestVerifiedDraw(), 'no VERIFIED row for a rejected draw');
    assert_true(in_array('LOTTERY_DRAW_VALIDATION_FAILED', array_column($audit->events, 'type'), true),
        'the rejection is audited');
    foreach ($repo->draws as $d) {
        assert_not_equals('VERIFIED', $d['verification_status'], 'verified=true is only ever set after successful validation');
    }
});

test('last verified draw: EuroMillions is ONE shared draw — no per-country winning numbers', function () {
    $repo = new LotteryRepositoryStub();
    $intel = new LotteryIntelligence($repo, fx_lastdraw_audit(), new \AIWorkforce\Lottery\UnavailableLotteryProvider());

    $lotteries = $intel->status()['lotteries'];
    assert_equals(1, count($lotteries), 'exactly one lottery product');
    assert_equals('EUROMILLIONS', $lotteries[0]['code']);
    assert_equals('EuroMillions', $lotteries[0]['name']);

    // The stored winning line is a single row — nothing is keyed by country.
    $src = file_get_contents(FCPATH . 'application/libraries/AIWorkforce/Lottery/LotteryIntelligence.php');
    assert_false(str_contains(strtolower($src), 'country_'), 'no per-country draw columns');
    $repoList = $repo->listDraws(['lotteryCode' => 'EUROMILLIONS'], 10);
    assert_equals([], $repoList, 'a fresh registry holds no draw rows at all');
});

test('last verified draw: the Sept 4 numbers and jackpot are never hardcoded into the UI', function () {
    $files = [
        'application/libraries/AIWorkforce/Lottery/LotteryIntelligence.php',
        'application/libraries/AIWorkforce/Lottery/LoteriasApiProvider.php',
        'application/controllers/Api_lottery.php',
        'application/controllers/Workspace.php',
        'application/views/workspace/index.php',
        'application/views/lottery/index.php',
        'assets/js/lottery.js',
        'apps/web/src/app/api/lottery/dashboard/route.ts',
        'apps/web/src/components/lottery/EuroMillionsWidget.tsx',
        'apps/web/src/components/lottery/LotteryWidget.tsx',
    ];
    foreach ($files as $file) {
        $path = FCPATH . $file;
        if (!is_file($path)) continue;
        $src = file_get_contents($path);
        assert_false((bool) preg_match('/89[.\s]?319[.\s]?120/', $src), 'no hardcoded jackpot in ' . $file);
        assert_false((bool) preg_match('/11\s*[,\-–·]\s*12\s*[,\-–·]\s*19\s*[,\-–·]\s*27\s*[,\-–·]\s*46/', $src), 'no hardcoded winning line in ' . $file);
        assert_not_contains('35000000', $src, 'no synthetic jackpot literal in ' . $file);
    }
});

<?php
/**
 * Cron odds job on the round path: matches that store a round_id on a
 * round-capable provider sync as whole matchdays (one provider call per
 * round) instead of per-fixture odds calls; everything else keeps the
 * legacy per-fixture path.
 */
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\SportsCronService;
use AIWorkforce\Sports\SportsDataNormalizer;
use AIWorkforce\Sports\SportsIntelligence;

function fx_cron_audit(): AuditRepository
{
    return new class implements AuditRepository { public array $events = []; public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'detail' => $d]; } public function recent(int $l = 100): array { return []; } };
}

function fx_cron_round_provider(): SportsDataProvider
{
    $now = gmdate('c');
    return new class($now) implements SportsDataProvider {
        public int $roundCalls = 0;
        public int $oddsCalls = 0;
        public function __construct(private string $now) {}
        public function id(): string { return 'cron-round-test'; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.95]; }
        public function fixtures(array $q): array { return $this->roundFixtures(); }
        public function round(string $roundId): array
        {
            $this->roundCalls++;
            if ($roundId !== 'cr-round-1') throw new \AIWorkforce\Sports\Providers\ProviderException('unknown round', \AIWorkforce\Sports\Providers\ProviderException::DATA_ERROR);
            return [
                'roundId' => $roundId, 'name' => '1', 'leagueId' => '1', 'league' => 'Test League', 'season' => '2026',
                'startingAt' => null, 'endingAt' => null, 'finished' => false,
                'fixtures' => $this->roundFixtures(),
                'odds' => [
                    ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.55, 'observedAt' => $this->now, 'bookmaker' => 'test', 'fixtureId' => 'cr-1'],
                    ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.75, 'observedAt' => $this->now, 'bookmaker' => 'test', 'fixtureId' => 'cr-2'],
                ],
                'results' => [],
            ];
        }
        public function odds(string $e): array
        {
            $this->oddsCalls++;
            return [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 2.0, 'observedAt' => $this->now]];
        }
        public function results(string $e): array { return []; }
        private function roundFixtures(): array
        {
            return [
                ['externalId' => 'cr-1', 'homeTeam' => 'Round Home 1', 'awayTeam' => 'Round Away 1', 'competition' => 'Test League', 'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', strtotime('today 14:00:00')), 'status' => 'SCHEDULED', 'roundId' => 'cr-round-1'],
                ['externalId' => 'cr-2', 'homeTeam' => 'Round Home 2', 'awayTeam' => 'Round Away 2', 'competition' => 'Test League', 'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', strtotime('today 16:00:00')), 'status' => 'SCHEDULED', 'roundId' => 'cr-round-1'],
            ];
        }
    };
}

test('cron odds job: round-addressable matches sync in one round() call, legacy matches per fixture', function () {
    $repo = ci()->AIWorkforce_model->sports;
    $audit = fx_cron_audit();
    $sports = new SportsIntelligence($repo, $audit);
    $fake = fx_cron_round_provider();
    $sports->providers->register($fake);
    $source = $repo->ensureProvider('cron-round-test', 'Cron Round Test');
    $sourceId = (int) $source['id'];

    $date = gmdate('Y-m-d');
    $mk = fn(string $ext, string $roundId, int $hour) => SportsDataNormalizer::fixture([
        'externalId' => $ext, 'homeTeam' => 'H' . $ext, 'awayTeam' => 'A' . $ext, 'competition' => 'Test League',
        'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', strtotime($date . ' ' . $hour . ':00:00')), 'status' => 'SCHEDULED', 'roundId' => $roundId,
    ], 'cron-round-test');
    $m1 = $repo->saveMatch($sourceId, $mk('cr-1', 'cr-round-1', 14));
    $m2 = $repo->saveMatch($sourceId, $mk('cr-2', 'cr-round-1', 16));
    $m3 = $repo->saveMatch($sourceId, $mk('cr-3', '', 18)); // no round id → legacy path

    $service = new SportsCronService($repo, $audit, $sports);
    $summary = $service->run('odds', $date);
    assert_equals('COMPLETED', $summary['status']);

    assert_equals(1, $fake->roundCalls, 'one round() call for the whole matchday');
    assert_equals(1, $fake->oddsCalls, 'per-fixture odds() only for the match without a round id');

    // Round-derived odds persisted (decimal 1.55 from the round payload, not 2.0 from per-fixture).
    $o1 = $repo->latestOdds((int) $m1['id'], 'TOTAL_GOALS', 'OVER_1_5');
    $o2 = $repo->latestOdds((int) $m2['id'], 'TOTAL_GOALS', 'OVER_1_5');
    $o3 = $repo->latestOdds((int) $m3['id'], 'TOTAL_GOALS', 'OVER_1_5');
    assert_not_null($o1); assert_equals(1.55, (float) $o1['decimal_odds']);
    assert_not_null($o2); assert_equals(1.75, (float) $o2['decimal_odds']);
    assert_not_null($o3); assert_equals(2.0, (float) $o3['decimal_odds'], 'legacy match used the per-fixture odds call');

    // Idempotency: re-running the same day neither re-fetches the round nor the legacy odds.
    $service->run('odds', $date);
    assert_equals(1, $fake->roundCalls, 'duplicate run does not re-fetch the round');
    assert_equals(1, $fake->oddsCalls, 'duplicate run does not re-fetch per-fixture odds');
});

test('schema: sports_matches persists round_id end to end', function () {
    $repo = ci()->AIWorkforce_model->sports;
    $audit = fx_cron_audit();
    $sports = new SportsIntelligence($repo, $audit);
    $source = $repo->ensureProvider('cron-round-schema', 'Cron Round Schema');
    $saved = $repo->saveMatch((int) $source['id'], SportsDataNormalizer::fixture([
        'externalId' => 'cr-schema-1', 'homeTeam' => 'H', 'awayTeam' => 'A', 'competition' => 'L',
        'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00'), 'status' => 'SCHEDULED', 'roundId' => 'cr-round-9',
    ], 'cron-round-schema'));
    $row = $repo->findMatch((int) $source['id'], 'cr-schema-1');
    assert_not_null($row);
    assert_equals('cr-round-9', (string) ($row['round_id'] ?? ''), 'round_id column round-trips through the real repository');
});

test('cron: runAll executes the cleanup job instead of failing on it', function () {
    $repo = ci()->AIWorkforce_model->sports;
    $audit = fx_cron_audit();
    $sports = new SportsIntelligence($repo, $audit);
    $service = new SportsCronService($repo, $audit, $sports);
    $summary = $service->runAll();

    assert_true(isset($summary['cleanup']), 'cleanup is part of the scheduled sweep');
    $status = (string) ($summary['cleanup']['status'] ?? '');
    assert_true(in_array($status, ['COMPLETED', 'DUPLICATE_SKIPPED'], true), 'cleanup runs (got ' . ($status ?: 'none') . ')');
    foreach ($summary as $job => $s) {
        assert_true(($s['status'] ?? '') !== 'FAILED', "job {$job} must not fail in a no-provider sweep");
    }
});

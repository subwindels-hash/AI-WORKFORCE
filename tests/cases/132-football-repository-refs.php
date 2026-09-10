<?php
/**
 * Regression: reading stored fixtures must survive real rows.
 *
 * FootballRepositoryDatabase memoizes the competition/provider lookups that
 * decorate every fixture row (a per-request cache added to keep the WASM
 * preview cheap). The cache lives on $this, so a closure that consults it has
 * to be *bound*: `static fn(int $id): bool => !isset($this->competitionMemo[$id])`
 * raises "Using $this when not in object context" — a fatal Error that took the
 * whole /football page (and every JSON read that lists fixtures) down with a 500
 * for any fixture carrying a competition_id.
 *
 * The stubbed-repository cases could never see it, and empty demo data skips the
 * lookup entirely (no row has a competition), so this case seeds a real
 * competition-linked fixture through the repository's own write path, reads it
 * back twice (the second read is the memo hit) and then reads an orphan fixture
 * whose competition row does not exist — the NULL-sentinel path.
 */
test('football: fixture reads join competition + provider refs, memo-safe', function () {
    if (!function_exists('get_instance')) {
        assert_true(true, 'CI-only: this case needs the installed database');
        return;
    }
    $db = ci()->db;
    $repo = new \AIWorkforce\Persistence\FootballRepositoryDatabase($db);
    $tag = 'fxtest-memo-';
    $cleanup = static function () use ($db, $tag): void {
        foreach (['1', '2', 'orphan'] as $suffix) {
            $db->where('external_id', $tag . 'fixture-' . $suffix)->delete('football_fixtures');
        }
        $db->where('external_id', $tag . 'competition')->delete('football_competitions');
        $db->where('provider_code', $tag . 'provider')->delete('football_providers');
    };
    $cleanup();
    $kickoff = time() + 3600;
    $day = gmdate('Y-m-d', $kickoff);
    $provider = $repo->ensureProvider($tag . 'provider', [
        'displayName' => 'Test Feed', 'status' => 'CONNECTED', 'enabled' => true,
    ]);
    $providerId = (int) $provider['id'];
    $competition = $repo->saveCompetition($providerId, [
        'externalId' => $tag . 'competition', 'name' => 'Test League', 'country' => 'Testland',
        'season' => '2026', 'dataState' => 'AVAILABLE',
    ]);
    foreach (['1' => (int) $competition['id'], '2' => (int) $competition['id'], 'orphan' => 987654321] as $suffix => $competitionId) {
        $repo->saveFixture($providerId, [
            'externalId' => $tag . 'fixture-' . $suffix,
            'competitionId' => $competitionId,
            'competition' => 'Test League', 'country' => 'Testland',
            'kickoff' => gmdate('c', $kickoff),
            'status' => 'SCHEDULED', 'matchState' => 'PRE_MATCH',
            'homeTeam' => 'Memo Home ' . $suffix, 'awayTeam' => 'Memo Away ' . $suffix,
            'homeTeamId' => 'MH' . $suffix, 'awayTeamId' => 'MA' . $suffix,
            'dataState' => 'AVAILABLE',
        ]);
    }
    try {
        $mine = static function (array $rows) use ($tag): array {
            return array_values(array_filter(
                $rows,
                static fn(array $row): bool => str_starts_with((string) ($row['external_id'] ?? ''), $tag . 'fixture-')
            ));
        };
        // Read 1 populates the memo, read 2 answers from it. Both must be joined.
        foreach ([1, 2] as $read) {
            $rows = $mine($repo->listFixtures(['date' => $day]));
            assert_equals(3, count($rows), 'every stored fixture came back on read ' . $read);
            foreach ($rows as $row) {
                assert_equals($tag . 'provider', $row['provider_code'], 'provider ref joined on read ' . $read);
                if (($row['external_id'] ?? '') === $tag . 'fixture-orphan') {
                    assert_null($row['competition_external_id'] ?? null, 'a missing competition stays a NULL reference');
                    continue;
                }
                assert_equals($tag . 'competition', $row['competition_external_id'], 'competition ref joined on read ' . $read);
                assert_equals('Testland', $row['competition_country'], 'competition country joined on read ' . $read);
            }
        }
        // A fresh instance has an empty memo: the orphan id must resolve to the
        // NULL sentinel instead of re-querying on every row or failing.
        $fresh = new \AIWorkforce\Persistence\FootballRepositoryDatabase($db);
        $rows = $mine($fresh->listFixtures(['date' => $day]));
        assert_equals(3, count($rows), 'a cold cache reads the same rows');
        assert_equals(2, count(array_filter($rows, static fn(array $r): bool => ($r['competition_external_id'] ?? null) === $tag . 'competition')),
            'only the two linked fixtures resolve the competition');
        assert_equals(1, count(array_filter($rows, static fn(array $r): bool => ($r['competition_external_id'] ?? null) === null)),
            'the orphan fixture stays a NULL reference on a cold cache');
    } finally {
        $cleanup();
    }
});

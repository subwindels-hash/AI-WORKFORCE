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

test('football: settling a prediction read back through decode() still flips it to SETTLED', function () {
    // Regression: savePrediction accepted pre-encoded rows from the prediction
    // writer, but settlement re-saves a row it read through decode() — JSON
    // columns arriving as raw arrays. The UPDATE then failed silently (CI query
    // builder cannot bind arrays), the settlement row and the fixture stamp
    // were written, and the prediction itself stayed OPEN forever — so the
    // board kept offering a graded match as open and every settle run re-scanned it.
    if (!function_exists('get_instance')) {
        assert_true(true, 'CI-only: this case needs the installed database');
        return;
    }
    $db = ci()->db;
    $repo = new \AIWorkforce\Persistence\FootballRepositoryDatabase($db);
    $pid = 'fxtest-settle-flip-' . bin2hex(random_bytes(4));
    $cleanup = static function () use ($db, $pid): void {
        $db->where('id', $pid)->delete('football_match_predictions');
    };
    $cleanup();
    try {
        $repo->savePrediction([
            'id' => $pid, 'fixture_id' => 987654321, 'provider_id' => 1, 'model_version_id' => null,
            'prediction_kind' => 'PRE_MATCH', 'generated_at' => gmdate('c'), 'kickoff_at' => gmdate('c', time() + 3600),
            'status_at_prediction' => 'SCHEDULED',
            'predicted_result' => 'HOME', 'predicted_home_score' => 2, 'predicted_away_score' => 1,
            'probability_home' => 0.55, 'probability_draw' => 0.30, 'probability_away' => 0.15,
            'confidence' => 55.0, 'confidence_basis' => 'RAW',
            'data_quality_score' => 70, 'data_quality_band' => 'QUALIFIED',
            'quality_components' => ['form' => 0.5, 'venue' => 0.5],
            'feature_snapshot' => ['form' => ['last5' => 'WWDWL'], 'odds' => null],
            'evidence' => [['kind' => 'form', 'detail' => 'last five']],
            'settlement_state' => 'OPEN',
        ]);
        // Settlement path: read back (decode) and write forward with the patch.
        $stored = $repo->findPrediction($pid);
        assert_true(is_array($stored) && is_array($stored['quality_components'] ?? null), 'the row reads back decoded');
        $repo->savePrediction(array_merge($stored, [
            'settlement_state' => 'SETTLED', 'outcome' => 'Final 2–1 (Home). Predicted HOME 2–1. Result correct; exact score correct.',
        ]));
        $after = $db->get_where('football_match_predictions', ['id' => $pid], 1)->row_array();
        assert_equals('SETTLED', (string) ($after['settlement_state'] ?? ''), 'the settlement flip survives the decoded round-trip');
        assert_true(is_string($after['quality_components'] ?? null) && json_decode((string) $after['quality_components'], true) !== null,
            'and the JSON columns are stored encoded, still valid JSON');
        assert_equals('Final 2–1 (Home). Predicted HOME 2–1. Result correct; exact score correct.', (string) ($after['outcome'] ?? ''),
            'the grading outcome is on the row');

        // Immutability holds whichever way the row was written: a settled
        // prediction refuses further edits.
        $repo->savePrediction(array_merge($stored, ['settlement_state' => 'SETTLED', 'outcome' => 'tampered']));
        $frozen = $repo->findPrediction($pid);
        assert_equals('Final 2–1 (Home). Predicted HOME 2–1. Result correct; exact score correct.', (string) ($frozen['outcome'] ?? ''),
            'a settled prediction is frozen for reproducibility');
    } finally {
        $cleanup();
    }
});

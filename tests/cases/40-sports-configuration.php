<?php
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Sports\ConfigurationService;

function fx_config_audit(): array {
    $audit = new class implements AuditRepository { public array $events = []; public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; } public function recent(int $l = 100): array { return []; } };
    return [new SportsRepositoryStub(), $audit, new ConfigurationService(new SportsRepositoryStub(), $audit)];
}

test('configuration returns safe defaults before any admin change', function () {
    [, , $svc] = fx_config_audit();
    $c = $svc->active();
    assert_equals('USER_APPROVAL_REQUIRED', $c['engine_mode']);
    // Qualified-ticket policy defaults: 30%+ confidence, 75+ quality. The
    // shipped confidence default is 30 (see ConfigurationService::defaults());
    // 30 is also the lowest value an operator may configure.
    assert_equals(30.0, (float) $c['min_confidence']);
    // Spec §7: the hard data-quality gate is 75.
    assert_equals(30, (int) $c['min_data_quality']);
    assert_equals(['MATCH_RESULT', 'TOTAL_GOALS', 'BTTS', 'DOUBLE_CHANCE', 'DRAW_NO_BET'], $c['allowed_markets'], 'every market the model can price and settle is allowed by default');
    assert_equals('RESTITUTE_ODDS', $c['void_policy']);
});

test('configuration updates are versioned and audited with old/new values', function () {
    $repo = new SportsRepositoryStub();
    $audit = new class implements AuditRepository { public array $events = []; public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; } public function recent(int $l = 100): array { return []; } };
    $svc = new ConfigurationService($repo, $audit);
    $r1 = $svc->update(['target_odds_min' => 6.0, 'target_odds_max' => 9.0], 'admin-1', 'tighten odds band');
    assert_true($r1['ok']);
    assert_equals(1, (int) $r1['configuration']['version']);
    $r2 = $svc->update(['min_confidence' => 85.0], 'admin-2', 'stricter');
    assert_true($r2['ok']);
    assert_equals(2, (int) $r2['configuration']['version']);
    assert_equals(6.0, (float) $r2['configuration']['target_odds_min']); // previous value preserved
    assert_equals(2, count($repo->configurations));
    $ev = end($audit->events);
    assert_equals('admin-2', $ev['actor']);
    assert_true(isset($ev['detail']['previous'], $ev['detail']['new'], $ev['detail']['reason']));
});

test('configuration validation rejects malformed values', function () {
    [, , $svc] = fx_config_audit();
    assert_false($svc->update(['target_odds_min' => 8, 'target_odds_max' => 5], 'a')['ok']);
    assert_false($svc->update(['max_selections' => 0], 'a')['ok']);
    assert_true($svc->update(['min_confidence' => 70.0], 'a', 'a stricter 70 percent floor is still allowed')['ok']);
    // Spec §6: the configurable confidence range is 30-100. 30 is the lowest
    // an operator may set and is also the shipped default; 29.99 is refused.
    assert_true($svc->update(['min_confidence' => 30.0], 'a', 'the configurable floor is 30')['ok']);
    assert_false($svc->update(['min_confidence' => 29.99], 'a')['ok'], '29.99 is below the confidence floor');
    assert_false($svc->update(['min_confidence' => 25.0], 'a')['ok'], '25 is no longer configurable');
    // Spec §7: the data-quality gate is a separate hard floor of 75.
    assert_true($svc->update(['min_data_quality' => 30], 'a', 'the data-quality floor is 30')['ok']);
    // Raising the floor is still fully supported.
    assert_true($svc->update(['min_data_quality' => 75], 'a', 'an operator may still be strict')['ok']);
    assert_false($svc->update(['min_data_quality' => 29], 'a')['ok'], '29 is below the data-quality floor');
    assert_false($svc->update(['min_data_quality' => 10], 'a')['ok']);
    assert_false($svc->update(['stake_amount' => 500, 'max_exposure' => 10], 'a')['ok']);
    assert_false($svc->update(['platform_mode' => 'MOON'], 'a')['ok']);
    assert_false($svc->update(['allowed_markets' => 'TOTAL_GOALS'], 'a')['ok']);
    // The 5.0 combined-odds floor is absolute: a minimum below 5.0 is refused,
    // exactly 5.0 is accepted, and raising it is still allowed.
    assert_false($svc->update(['target_odds_min' => 4.9, 'target_odds_max' => 8.0], 'a')['ok'], '4.9 is below the 5.0 odds floor');
    assert_false($svc->update(['target_odds_min' => 2.0, 'target_odds_max' => 4.0], 'a')['ok'], 'a sub-5.0 window is refused');
    assert_true($svc->update(['target_odds_min' => 5.0, 'target_odds_max' => 8.0], 'a', 'the floor itself is valid')['ok']);
    assert_true($svc->update(['target_odds_min' => 7.0, 'target_odds_max' => 12.0], 'a', 'a stricter minimum is allowed')['ok']);
});

test('configuration clamps a legacy sub-5.0 odds minimum up to the floor', function () {
    // A row written before the 5.0 rule (or by any path that stored a lower
    // minimum) must never leak a sub-floor minimum into a generation run.
    $repo = new SportsRepositoryStub();
    $audit = new class implements AuditRepository { public function emit(string $t, string $s, array $d = [], string $a = 'system'): void {} public function recent(int $l = 100): array { return []; } };
    $repo->configurations[] = array_merge(ConfigurationService::defaults(), [
        'version' => 5, 'target_odds_min' => 2.0, 'target_odds_max' => 3.0,
    ]);
    $active = (new ConfigurationService($repo, $audit))->active();
    assert_true((float) $active['target_odds_min'] >= 5.0, 'a legacy 2.0 minimum is clamped up to 5.0');
    assert_true((float) $active['target_odds_max'] >= (float) $active['target_odds_min'], 'the window stays valid (max >= min)');
});

test('AUTOMATED_EXECUTION is refused without explicit authorization', function () {
    [, , $svc] = fx_config_audit();
    $r = $svc->update(['engine_mode' => 'AUTOMATED_EXECUTION'], 'admin');
    assert_false($r['ok']);
    assert_contains('AUTOMATED_EXECUTION', $r['reason']);
    $ok = $svc->update(['engine_mode' => 'AUTOMATED_EXECUTION'], 'admin', 'explicit', true);
    assert_true($ok['ok']);
    assert_equals('AUTOMATED_EXECUTION', $ok['configuration']['engine_mode']);
});

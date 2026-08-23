<?php
use Aegis\ExecutionSupervisor;
use Aegis\Persistence\AuditRepository;

function fx_supervisor(): array {
    $audit = new class implements AuditRepository {
        public array $events = [];
        public function emit(string $type, string $summary, array $detail = [], string $actor = 'system'): void { $this->events[] = $type; }
        public function recent(int $limit = 100): array { return []; }
    };
    return [new ExecutionSupervisor($audit), $audit];
}

test('execution supervisor rejects preflight while kill switch is active', function () {
    [$supervisor, $audit] = fx_supervisor();
    $result = $supervisor->preflight(['symbol' => 'EURUSD'], ['tradingMode' => 'HUMAN_APPROVAL', 'killSwitch' => ['active' => true]]);
    assert_equals('REJECTED', $result['status']);
    assert_equals('EXECUTION_PREFLIGHT_REJECTED', $audit->events[0]);
});

test('execution supervisor requires a human mode and mandatory stop', function () {
    [$supervisor] = fx_supervisor();
    $state = ['tradingMode' => 'HUMAN_APPROVAL', 'killSwitch' => ['active' => false]];
    $result = $supervisor->preflight(['symbol' => 'EURUSD', 'side' => 'BUY', 'quantity' => 1], $state);
    assert_equals('REJECTED', $result['status']);
    assert_contains('stopLoss', $result['reason']);
});

test('execution supervisor passes only to approval required and never executes', function () {
    [$supervisor, $audit] = fx_supervisor();
    $state = ['tradingMode' => 'HUMAN_APPROVAL', 'killSwitch' => ['active' => false]];
    $result = $supervisor->preflight(['symbol' => 'EURUSD', 'side' => 'BUY', 'quantity' => 1, 'stopLoss' => 1.07, 'secret' => 'excluded'], $state);
    assert_equals('APPROVAL_REQUIRED', $result['status']);
    assert_false(isset($result['intent']['secret']));
    assert_equals('EXECUTION_PREFLIGHT_PASSED', $audit->events[0]);
});

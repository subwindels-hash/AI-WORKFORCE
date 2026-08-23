<?php
namespace Aegis;

use Aegis\Persistence\AuditRepository;

/**
 * Phase 5 execution gate. It deliberately creates no broker order and has no
 * connector dependency: this is the deterministic preflight/approval layer
 * that must sit before any future execution adapter.
 */
class ExecutionSupervisor
{
    public function __construct(private AuditRepository $audit) {}

    public function preflight(array $intent, array $state): array
    {
        $id = 'pre_' . bin2hex(random_bytes(8));
        $checks = [];
        $reject = function (string $check, string $reason) use (&$checks, $id, $intent): array {
            $checks[] = ['check' => $check, 'ok' => false, 'detail' => $reason];
            $result = ['id' => $id, 'status' => 'REJECTED', 'reason' => $reason, 'checks' => $checks, 'intent' => $this->safeIntent($intent)];
            $this->audit->emit('EXECUTION_PREFLIGHT_REJECTED', $reason, $result, 'system');
            return $result;
        };
        if (($state['killSwitch']['active'] ?? true) === true) return $reject('kill-switch', 'kill switch is active');
        $checks[] = ['check' => 'kill-switch', 'ok' => true, 'detail' => 'inactive'];
        if (($state['tradingMode'] ?? '') !== 'HUMAN_APPROVAL') return $reject('mode', 'HUMAN_APPROVAL mode is required for execution preflight');
        $checks[] = ['check' => 'mode', 'ok' => true, 'detail' => 'HUMAN_APPROVAL'];
        foreach (['symbol', 'side', 'quantity', 'stopLoss'] as $field) {
            if (!isset($intent[$field]) || $intent[$field] === '') return $reject('intent', "missing {$field}");
        }
        if (!in_array($intent['side'], ['BUY', 'SELL'], true) || !is_numeric($intent['quantity']) || (float)$intent['quantity'] <= 0 || !is_numeric($intent['stopLoss']) || (float)$intent['stopLoss'] <= 0) {
            return $reject('intent', 'invalid side, quantity, or mandatory stopLoss');
        }
        $checks[] = ['check' => 'intent', 'ok' => true, 'detail' => 'required fields and mandatory stop validated'];
        $checks[] = ['check' => 'human-approval', 'ok' => true, 'detail' => 'approval required before any future routing'];
        $result = ['id' => $id, 'status' => 'APPROVAL_REQUIRED', 'reason' => 'preflight passed; no broker order was created', 'checks' => $checks, 'intent' => $this->safeIntent($intent)];
        $this->audit->emit('EXECUTION_PREFLIGHT_PASSED', 'Execution preflight requires human approval', $result, 'system');
        return $result;
    }

    private function safeIntent(array $intent): array
    {
        return array_intersect_key($intent, array_flip(['symbol', 'side', 'quantity', 'stopLoss', 'takeProfit', 'orderType', 'reason', 'strategyId']));
    }
}

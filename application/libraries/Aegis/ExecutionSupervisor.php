<?php
namespace Aegis;

use Aegis\Persistence\AuditRepository;
use Aegis\Persistence\PlatformStateRepository;

/**
 * Phase 5 execution gate. It deliberately creates no broker order and has no
 * connector dependency: this is the deterministic preflight/approval layer
 * that must sit before any future execution adapter.
 */
class ExecutionSupervisor
{
    public function __construct(private AuditRepository $audit, private ?PlatformStateRepository $stateRepo = null) {}

    /** Persist an approved preflight as a human review request; it is never routed. */
    public function requestApproval(array $intent, array $state, string $actor = 'user'): array
    {
        $result = $this->preflight($intent, $state);
        if ($result['status'] !== 'APPROVAL_REQUIRED') return $result;
        if ($this->stateRepo === null) throw new \RuntimeException('approval persistence unavailable');
        $state = $this->stateRepo->load();
        $requests = $state['executionApprovals'] ?? [];
        $request = ['id' => 'apr_' . bin2hex(random_bytes(8)), 'status' => 'PENDING', 'createdAt' => gmdate('c'), 'decidedAt' => null, 'actor' => $actor, 'decisionBy' => null, 'decisionReason' => null, 'preflight' => $result];
        $requests[] = $request;
        $state['executionApprovals'] = array_slice($requests, -200);
        $this->stateRepo->save($state);
        $this->audit->emit('EXECUTION_APPROVAL_REQUESTED', 'Human approval request created', ['id' => $request['id'], 'intent' => $result['intent']], $actor);
        return $request;
    }

    public function approvals(array $state): array { return array_reverse($state['executionApprovals'] ?? []); }

    public function decide(string $id, bool $approve, string $actor = 'user', ?string $reason = null): array
    {
        if ($this->stateRepo === null) throw new \RuntimeException('approval persistence unavailable');
        $state = $this->stateRepo->load(); $requests = $state['executionApprovals'] ?? [];
        foreach ($requests as &$request) {
            if (($request['id'] ?? '') !== $id) continue;
            if ($request['status'] !== 'PENDING') throw new \RuntimeException('approval request has already been decided');
            $request['status'] = $approve ? 'APPROVED_NOT_ROUTED' : 'REJECTED';
            $request['decidedAt'] = gmdate('c'); $request['decisionBy'] = $actor; $request['decisionReason'] = $reason;
            $state['executionApprovals'] = $requests; $this->stateRepo->save($state);
            $this->audit->emit($approve ? 'EXECUTION_APPROVAL_GRANTED' : 'EXECUTION_APPROVAL_REJECTED', $approve ? 'Approval granted; routing remains disabled' : 'Approval request rejected', ['id' => $id, 'reason' => $reason], $actor);
            return $request;
        }
        throw new \InvalidArgumentException('approval request not found');
    }

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

<?php
namespace AIWorkforce\Cloudflare;

/**
 * Agent Observability — Monitoring, tracing, and analytics for the AI agent platform
 *
 * Provides a unified view of:
 * - Active agents and their state
 * - Agent executions (success, failure, latency)
 * - Tool calls and their outcomes
 * - Model calls (tokens, cost, latency)
 * - Workflow status and progress
 * - Provider health
 * - Error rates and patterns
 * - Approval requests
 *
 * Every important agent action has a traceable execution ID.
 */
class AgentObservability
{
    /** @var \CI_DB_query_builder */
    private $db;

    /** @var ModelRouter|null */
    private ?ModelRouter $modelRouter;

    /** @var AgentSessionManager|null */
    private ?AgentSessionManager $sessionManager;

    /** @var WorkflowEngine|null */
    private ?WorkflowEngine $workflowEngine;

    public function __construct(
        $db,
        ?ModelRouter $modelRouter = null,
        ?AgentSessionManager $sessionManager = null,
        ?WorkflowEngine $workflowEngine = null
    ) {
        $this->db = $db;
        $this->modelRouter = $modelRouter;
        $this->sessionManager = $sessionManager;
        $this->workflowEngine = $workflowEngine;
    }

    /**
     * Get comprehensive dashboard data
     */
    public function dashboard(): array
    {
        return [
            'overview' => $this->overview(),
            'agents' => $this->agentStatus(),
            'models' => $this->modelStatus(),
            'workflows' => $this->workflowStatus(),
            'sessions' => $this->sessionStatus(),
            'recentActivity' => $this->recentActivity(20),
            'health' => $this->systemHealth(),
        ];
    }

    /**
     * High-level overview metrics
     */
    public function overview(): array
    {
        $today = gmdate('Y-m-d');
        $weekAgo = gmdate('Y-m-d', time() - 7 * 86400);

        // Agent executions today
        $todayExecutions = $this->db
            ->where('created_at >=', $today)
            ->count_all_results('audit_log');

        // Total executions this week
        $weekExecutions = $this->db
            ->where('created_at >=', $weekAgo)
            ->count_all_results('audit_log');

        // Error rate
        $errors = $this->db
            ->where('created_at >=', $today)
            ->where('type LIKE', '%FAILED%')
            ->count_all_results('audit_log');

        // Active sessions
        $activeSessions = 0;
        if ($this->sessionManager) {
            $stats = $this->sessionManager->stats();
            $activeSessions = $stats['active'] ?? 0;
        }

        // Pending workflows
        $pendingWorkflows = 0;
        if ($this->workflowEngine) {
            $wfStats = $this->workflowEngine->stats();
            $pendingWorkflows = $wfStats['byStatus']['PENDING'] ?? 0;
        }

        // Model usage
        $modelUsage = [];
        if ($this->modelRouter) {
            $modelUsage = $this->modelRouter->usageStats();
        }

        return [
            'todayExecutions' => $todayExecutions,
            'weekExecutions' => $weekExecutions,
            'todayErrors' => $errors,
            'errorRate' => $todayExecutions > 0 ? round($errors / $todayExecutions * 100, 1) : 0,
            'activeSessions' => $activeSessions,
            'pendingWorkflows' => $pendingWorkflows,
            'modelUsage' => $modelUsage,
        ];
    }

    /**
     * Agent status summary
     */
    public function agentStatus(): array
    {
        $agents = [];
        $types = ['AGENT_COMPLETED', 'AGENT_FAILED'];

        foreach ($types as $type) {
            $rows = $this->db
                ->select('detail')
                ->where('type', $type)
                ->where('created_at >=', gmdate('Y-m-d', time() - 86400))
                ->get('audit_log')
                ->result_array();

            foreach ($rows as $row) {
                $detail = json_decode((string) $row['detail'], true) ?: [];
                $agent = $detail['agent'] ?? 'unknown';
                if (!isset($agents[$agent])) {
                    $agents[$agent] = ['name' => $agent, 'completed' => 0, 'failed' => 0, 'avgLatencyMs' => 0, 'totalLatencyMs' => 0, 'count' => 0];
                }
                if ($type === 'AGENT_COMPLETED') {
                    $agents[$agent]['completed']++;
                } else {
                    $agents[$agent]['failed']++;
                }
                if (isset($detail['latencyMs'])) {
                    $agents[$agent]['totalLatencyMs'] += $detail['latencyMs'];
                    $agents[$agent]['count']++;
                }
            }
        }

        foreach ($agents as &$a) {
            $a['avgLatencyMs'] = $a['count'] > 0 ? round($a['totalLatencyMs'] / $a['count']) : 0;
            $a['successRate'] = ($a['completed'] + $a['failed']) > 0
                ? round($a['completed'] / ($a['completed'] + $a['failed']) * 100, 1)
                : 0;
            unset($a['totalLatencyMs'], $a['count']);
        }

        return $agents;
    }

    /**
     * Model provider status
     */
    public function modelStatus(): array
    {
        if (!$this->modelRouter) {
            return ['configured' => false];
        }
        return $this->modelRouter->status();
    }

    /**
     * Workflow status
     */
    public function workflowStatus(): array
    {
        if (!$this->workflowEngine) {
            return ['configured' => false];
        }
        return $this->workflowEngine->stats();
    }

    /**
     * Session status
     */
    public function sessionStatus(): array
    {
        if (!$this->sessionManager) {
            return ['configured' => false];
        }
        return $this->sessionManager->stats();
    }

    /**
     * System health check
     */
    public function systemHealth(): array
    {
        $health = [
            'overall' => 'HEALTHY',
            'components' => [],
        ];

        // Check model router
        if ($this->modelRouter) {
            $status = $this->modelRouter->status();
            $hasHealthy = false;
            foreach ($status['providers'] ?? [] as $name => $p) {
                if (($p['health']['status'] ?? 'UNKNOWN') === 'HEALTHY') {
                    $hasHealthy = true;
                }
            }
            $health['components']['model_router'] = $hasHealthy ? 'HEALTHY' : ($status['configured'] ? 'DEGRADED' : 'DOWN');
        } else {
            $health['components']['model_router'] = 'NOT_CONFIGURED';
        }

        // Check database
        try {
            $this->db->query("SELECT 1");
            $health['components']['database'] = 'HEALTHY';
        } catch (\Throwable $e) {
            $health['components']['database'] = 'DOWN';
            $health['overall'] = 'DEGRADED';
        }

        // Check for too many errors
        $recentErrors = $this->db
            ->where('created_at >=', gmdate('Y-m-d H:i:s', time() - 3600))
            ->where('type LIKE', '%FAILED%')
            ->count_all_results('audit_log');

        if ($recentErrors > 50) {
            $health['components']['error_rate'] = 'HIGH';
            $health['overall'] = 'DEGRADED';
        } else {
            $health['components']['error_rate'] = 'NORMAL';
        }

        return $health;
    }

    /**
     * Recent activity feed
     */
    public function recentActivity(int $limit = 20): array
    {
        return $this->db
            ->order_by('created_at', 'DESC')
            ->limit($limit)
            ->get('audit_log')
            ->result_array();
    }

    /**
     * Get execution trace by ID
     */
    public function trace(string $executionId): array
    {
        return $this->db
            ->where('detail LIKE', '%' . $this->db->escape_like_str($executionId) . '%')
            ->order_by('created_at', 'ASC')
            ->get('audit_log')
            ->result_array();
    }

    /**
     * Get error summary
     */
    public function errorSummary(int $hours = 24): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - $hours * 3600);
        $errors = $this->db
            ->where('created_at >=', $since)
            ->where('type LIKE', '%FAILED%')
            ->order_by('created_at', 'DESC')
            ->limit(100)
            ->get('audit_log')
            ->result_array();

        $byType = [];
        foreach ($errors as $e) {
            $type = $e['type'] ?? 'UNKNOWN';
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        return [
            'total' => count($errors),
            'byType' => $byType,
            'recent' => array_slice($errors, 0, 10),
        ];
    }

    /**
     * Get tool usage statistics
     */
    public function toolStats(int $hours = 24): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - $hours * 3600);
        $toolCalls = $this->db
            ->where('created_at >=', $since)
            ->where('type', 'TOOL_EXECUTED')
            ->get('audit_log')
            ->result_array();

        $byTool = [];
        foreach ($toolCalls as $call) {
            $detail = json_decode((string) $call['detail'], true) ?: [];
            $tool = $detail['tool'] ?? 'unknown';
            if (!isset($byTool[$tool])) {
                $byTool[$tool] = ['count' => 0, 'totalLatencyMs' => 0];
            }
            $byTool[$tool]['count']++;
            $byTool[$tool]['totalLatencyMs'] += $detail['latencyMs'] ?? 0;
        }

        foreach ($byTool as &$t) {
            $t['avgLatencyMs'] = $t['count'] > 0 ? round($t['totalLatencyMs'] / $t['count']) : 0;
        }

        return $byTool;
    }

    /**
     * Cost summary
     */
    public function costSummary(int $days = 30): array
    {
        if (!$this->modelRouter) {
            return ['configured' => false];
        }

        $usage = $this->modelRouter->usageStats();
        return [
            'totalCostUsd' => $usage['totalCostUsd'] ?? 0,
            'totalTokens' => $usage['totalTokens'] ?? 0,
            'totalCalls' => $usage['totalCalls'] ?? 0,
            'byProvider' => $usage['byProvider'] ?? [],
            'byModel' => $usage['byModel'] ?? [],
            'byAgent' => $usage['byAgent'] ?? [],
        ];
    }
}

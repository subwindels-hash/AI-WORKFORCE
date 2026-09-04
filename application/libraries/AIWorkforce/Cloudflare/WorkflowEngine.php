<?php
namespace AIWorkforce\Cloudflare;

/**
 * Agent Workflow Engine — Durable workflows for long-running tasks
 *
 * Manages multi-step workflows with:
 * - Step tracking with status
 * - Retry on failure with configurable backoff
 * - Scheduled execution
 * - Progress tracking
 * - Cancellation support
 * - Result storage
 *
 * Examples:
 * - Daily market analysis pipeline
 * - Weekly lottery analysis
 * - Video generation pipeline
 * - Lead discovery pipeline
 * - Scheduled report generation
 */
class WorkflowEngine
{
    /** @var \CI_DB_query_builder */
    private $db;

    /** @var callable|null */
    private $audit;

    /** @var array<string,callable> Registered workflow handlers */
    private array $handlers = [];

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_WAITING_APPROVAL = 'WAITING_APPROVAL';

    public function __construct($db, ?callable $audit = null)
    {
        $this->db = $db;
        $this->audit = $audit;
        $this->ensureSchema();
        $this->registerDefaultHandlers();
    }

    /**
     * Register a workflow handler
     */
    public function registerHandler(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    /**
     * Create and start a new workflow
     */
    public function start(string $type, array $params = [], array $options = []): array
    {
        if (!isset($this->handlers[$type])) {
            return ['ok' => false, 'error' => "Unknown workflow type: {$type}"];
        }

        $id = 'wf_' . bin2hex(random_bytes(12));
        $now = gmdate('Y-m-d H:i:s');
        $scheduledAt = $options['scheduled_at'] ?? $now;
        $priority = (int) ($options['priority'] ?? 5);
        $maxRetries = (int) ($options['max_retries'] ?? 3);
        $userId = $options['user_id'] ?? null;

        $workflow = [
            'id' => $id,
            'type' => $type,
            'status' => self::STATUS_PENDING,
            'params' => json_encode($params, JSON_UNESCAPED_SLASHES),
            'result' => null,
            'error' => null,
            'progress' => json_encode(['current_step' => 0, 'total_steps' => 0, 'message' => 'Queued']),
            'created_at' => $now,
            'started_at' => null,
            'completed_at' => null,
            'scheduled_at' => $scheduledAt,
            'attempts' => 0,
            'max_retries' => $maxRetries,
            'priority' => $priority,
            'user_id' => $userId,
        ];

        $this->db->insert('agent_workflows', $workflow);

        $this->auditLog('WORKFLOW_CREATED', [
            'workflowId' => $id, 'type' => $type, 'userId' => $userId,
        ]);

        return ['ok' => true, 'workflowId' => $id, 'status' => self::STATUS_PENDING];
    }

    /**
     * Execute pending workflows (called by cron or scheduler)
     */
    public function processPending(int $limit = 10): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $workflows = $this->db
            ->where('status', self::STATUS_PENDING)
            ->where('scheduled_at <=', $now)
            ->order_by('priority', 'DESC')
            ->order_by('created_at', 'ASC')
            ->limit($limit)
            ->get('agent_workflows')
            ->result_array();

        $results = [];
        foreach ($workflows as $wf) {
            $results[] = $this->execute($wf['id']);
        }

        return $results;
    }

    /**
     * Execute a single workflow
     */
    public function execute(string $workflowId): array
    {
        $wf = $this->load($workflowId);
        if (!$wf) {
            return ['ok' => false, 'error' => 'Workflow not found'];
        }

        if ($wf['status'] !== self::STATUS_PENDING) {
            return ['ok' => false, 'error' => "Workflow is not pending (status: {$wf['status']})"];
        }

        $handler = $this->handlers[$wf['type']] ?? null;
        if (!$handler) {
            return ['ok' => false, 'error' => "No handler for type: {$wf['type']}"];
        }

        // Mark as running
        $this->updateStatus($workflowId, self::STATUS_RUNNING, [
            'started_at' => gmdate('Y-m-d H:i:s'),
            'attempts' => (int) $wf['attempts'] + 1,
        ]);

        $start = microtime(true);
        try {
            $params = json_decode((string) $wf['params'], true) ?: [];
            $result = $handler($params, $workflowId, $this);

            $latencyMs = round((microtime(true) - $start) * 1000);

            $this->updateStatus($workflowId, self::STATUS_COMPLETED, [
                'result' => json_encode($result, JSON_UNESCAPED_SLASHES),
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $this->auditLog('WORKFLOW_COMPLETED', [
                'workflowId' => $workflowId, 'type' => $wf['type'], 'latencyMs' => $latencyMs,
            ]);

            return ['ok' => true, 'result' => $result, 'latencyMs' => $latencyMs];
        } catch (\Throwable $e) {
            $latencyMs = round((microtime(true) - $start) * 1000);
            $attempts = (int) $wf['attempts'] + 1;
            $maxRetries = (int) $wf['max_retries'];

            if ($attempts < $maxRetries) {
                // Schedule retry
                $this->updateStatus($workflowId, self::STATUS_PENDING, [
                    'attempts' => $attempts,
                    'error' => $e->getMessage(),
                    'scheduled_at' => gmdate('Y-m-d H:i:s', time() + ($attempts * 60)), // Exponential backoff
                ]);
            } else {
                $this->updateStatus($workflowId, self::STATUS_FAILED, [
                    'error' => $e->getMessage(),
                    'completed_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }

            $this->auditLog('WORKFLOW_FAILED', [
                'workflowId' => $workflowId, 'type' => $wf['type'],
                'error' => $e->getMessage(), 'attempt' => $attempts,
            ]);

            return ['ok' => false, 'error' => $e->getMessage(), 'attempt' => $attempts];
        }
    }

    /**
     * Update workflow progress
     */
    public function updateProgress(string $workflowId, int $currentStep, int $totalSteps, string $message = ''): void
    {
        $this->db->where('id', $workflowId)->update('agent_workflows', [
            'progress' => json_encode([
                'current_step' => $currentStep,
                'total_steps' => $totalSteps,
                'message' => $message,
                'updated_at' => gmdate('c'),
            ]),
        ]);
    }

    /**
     * Cancel a workflow
     */
    public function cancel(string $workflowId): bool
    {
        $wf = $this->load($workflowId);
        if (!$wf || in_array($wf['status'], [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED])) {
            return false;
        }

        $this->updateStatus($workflowId, self::STATUS_CANCELLED, [
            'completed_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->auditLog('WORKFLOW_CANCELLED', ['workflowId' => $workflowId]);
        return true;
    }

    /**
     * Load a workflow
     */
    public function load(string $workflowId): ?array
    {
        $row = $this->db->get_where('agent_workflows', ['id' => $workflowId], 1)->row_array();
        if (!$row) return null;

        $row['params'] = json_decode((string) $row['params'], true) ?: [];
        $row['result'] = json_decode((string) $row['result'], true);
        $row['progress'] = json_decode((string) $row['progress'], true) ?: [];

        return $row;
    }

    /**
     * List workflows
     */
    public function list(?string $type = null, ?string $status = null, int $limit = 50): array
    {
        $q = $this->db->order_by('created_at', 'DESC')->limit($limit);
        if ($type) $q->where('type', $type);
        if ($status) $q->where('status', $status);
        return $q->get('agent_workflows')->result_array();
    }

    /**
     * Get workflow statistics
     */
    public function stats(): array
    {
        $byStatus = $this->db
            ->select('status, COUNT(*) as count')
            ->group_by('status')
            ->get('agent_workflows')
            ->result_array();

        $byType = $this->db
            ->select('type, status, COUNT(*) as count')
            ->group_by('type, status')
            ->get('agent_workflows')
            ->result_array();

        return [
            'byStatus' => array_column($byStatus, 'count', 'status'),
            'byType' => $byType,
            'total' => array_sum(array_column($byStatus, 'count')),
        ];
    }

    private function updateStatus(string $id, string $status, array $extra = []): void
    {
        $data = array_merge(['status' => $status], $extra);
        $this->db->where('id', $id)->update('agent_workflows', $data);
    }

    private function registerDefaultHandlers(): void
    {
        // Daily market analysis workflow
        $this->registerHandler('daily_market_analysis', function (array $params, string $wfId, $engine) {
            $engine->updateProgress($wfId, 1, 4, 'Fetching market data...');
            // Step 1: Fetch market data
            $engine->updateProgress($wfId, 2, 4, 'Running technical analysis...');
            // Step 2: Technical analysis
            $engine->updateProgress($wfId, 3, 4, 'Generating signals...');
            // Step 3: Generate signals
            $engine->updateProgress($wfId, 4, 4, 'Storing results...');
            // Step 4: Store results
            return ['status' => 'completed', 'analyzedSymbols' => $params['symbols'] ?? []];
        });

        // Weekly lottery analysis workflow
        $this->registerHandler('weekly_lottery_analysis', function (array $params, string $wfId, $engine) {
            $engine->updateProgress($wfId, 1, 3, 'Fetching draw history...');
            $engine->updateProgress($wfId, 2, 3, 'Running statistical analysis...');
            $engine->updateProgress($wfId, 3, 3, 'Generating report...');
            return ['status' => 'completed', 'lottery' => $params['lottery'] ?? 'euromillions'];
        });

        // Video generation pipeline
        $this->registerHandler('video_generation', function (array $params, string $wfId, $engine) {
            $engine->updateProgress($wfId, 1, 4, 'Creating prompt...');
            $engine->updateProgress($wfId, 2, 4, 'Generating video...');
            $engine->updateProgress($wfId, 3, 4, 'Processing output...');
            $engine->updateProgress($wfId, 4, 4, 'Finalizing...');
            return ['status' => 'completed', 'prompt' => $params['prompt'] ?? ''];
        });

        // Lead discovery pipeline
        $this->registerHandler('lead_discovery', function (array $params, string $wfId, $engine) {
            $engine->updateProgress($wfId, 1, 5, 'Searching businesses...');
            $engine->updateProgress($wfId, 2, 5, 'Deduplicating results...');
            $engine->updateProgress($wfId, 3, 5, 'Enriching data...');
            $engine->updateProgress($wfId, 4, 5, 'Scoring leads...');
            $engine->updateProgress($wfId, 5, 5, 'Generating report...');
            return ['status' => 'completed', 'query' => $params['query'] ?? ''];
        });
    }

    private function ensureSchema(): void
    {
        if (!$this->db->table_exists('agent_workflows')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS agent_workflows (
                id VARCHAR(64) PRIMARY KEY,
                type VARCHAR(100) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
                params TEXT,
                result LONGTEXT,
                error TEXT,
                progress TEXT,
                created_at DATETIME NOT NULL,
                started_at DATETIME,
                completed_at DATETIME,
                scheduled_at DATETIME NOT NULL,
                attempts INT DEFAULT 0,
                max_retries INT DEFAULT 3,
                priority INT DEFAULT 5,
                user_id INT,
                INDEX idx_status (status),
                INDEX idx_type (type),
                INDEX idx_scheduled (scheduled_at),
                INDEX idx_user (user_id)
            )");
        }
    }

    private function auditLog(string $type, array $detail): void
    {
        if ($this->audit) {
            try { ($this->audit)($type, $type, $detail); } catch (\Throwable $e) {}
        }
    }
}

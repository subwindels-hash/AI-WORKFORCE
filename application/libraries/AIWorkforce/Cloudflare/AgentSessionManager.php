<?php
namespace AIWorkforce\Cloudflare;

/**
 * Agent Session Manager — Durable sessions and state for AI agents
 *
 * Manages persistent agent conversations with:
 * - Session creation, loading, and expiration
 * - Conversation history with token management
 * - Per-agent state (context, preferences, progress)
 * - Multi-turn conversation support
 * - Session recovery after failures
 *
 * In production, sessions can be backed by Cloudflare Durable Objects
 * for globally distributed, consistent state. Locally, sessions use
 * the application database.
 */
class AgentSessionManager
{
    /** @var \CI_DB_query_builder Database */
    private $db;

    /** @var callable|null Audit logger */
    private $audit;

    /** Session TTL in seconds (4 hours default) */
    private int $sessionTtl;

    /** Max conversation history to keep per session */
    private int $maxHistory;

    public function __construct($db, ?callable $audit = null, int $sessionTtl = 14400, int $maxHistory = 50)
    {
        $this->db = $db;
        $this->audit = $audit;
        $this->sessionTtl = $sessionTtl;
        $this->maxHistory = $maxHistory;
        $this->ensureSchema();
    }

    /**
     * Create or resume a session for a user + agent
     */
    public function getOrCreate(int $userId, string $agent, ?string $sessionId = null): array
    {
        if ($sessionId) {
            $session = $this->load($sessionId);
            if ($session && (int) $session['user_id'] === $userId && $session['agent'] === $agent) {
                if (!$this->isExpired($session)) {
                    return $session;
                }
                // Expired — create new session
            }
        }

        return $this->create($userId, $agent);
    }

    /**
     * Create a new session
     */
    public function create(int $userId, string $agent, array $metadata = []): array
    {
        $id = 'sess_' . bin2hex(random_bytes(12));
        $now = gmdate('Y-m-d H:i:s');

        $session = [
            'id' => $id,
            'user_id' => $userId,
            'agent' => $agent,
            'state' => json_encode(['context' => [], 'preferences' => []]),
            'history' => json_encode([]),
            'metadata' => json_encode($metadata),
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->sessionTtl),
            'message_count' => 0,
            'token_count' => 0,
        ];

        $this->db->insert('agent_sessions', $session);

        $this->auditLog('SESSION_CREATED', [
            'sessionId' => $id, 'userId' => $userId, 'agent' => $agent,
        ]);

        return $session;
    }

    /**
     * Load a session by ID
     */
    public function load(string $sessionId): ?array
    {
        $row = $this->db->get_where('agent_sessions', ['id' => $sessionId], 1)->row_array();
        if (!$row) return null;

        $row['state'] = json_decode((string) $row['state'], true) ?: [];
        $row['history'] = json_decode((string) $row['history'], true) ?: [];
        $row['metadata'] = json_decode((string) $row['metadata'], true) ?: [];

        return $row;
    }

    /**
     * Add a message to the conversation history
     */
    public function addMessage(string $sessionId, string $role, string $content, array $extra = []): array
    {
        $session = $this->load($sessionId);
        if (!$session) {
            return ['ok' => false, 'error' => 'Session not found'];
        }

        $history = $session['history'];
        $message = array_merge([
            'role' => $role,
            'content' => $content,
            'timestamp' => gmdate('c'),
        ], $extra);

        $history[] = $message;

        // Trim history to max size
        if (count($history) > $this->maxHistory) {
            $history = array_slice($history, -$this->maxHistory);
        }

        $tokenEstimate = (int) ceil(mb_strlen($content) / 4);
        $now = gmdate('Y-m-d H:i:s');

        $this->db->where('id', $sessionId)->update('agent_sessions', [
            'history' => json_encode($history, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $this->sessionTtl),
            'message_count' => (int) $session['message_count'] + 1,
            'token_count' => (int) $session['token_count'] + $tokenEstimate,
        ]);

        return [
            'ok' => true,
            'messageCount' => (int) $session['message_count'] + 1,
            'historyLength' => count($history),
        ];
    }

    /**
     * Update agent state (persistent context)
     */
    public function updateState(string $sessionId, array $state): bool
    {
        $session = $this->load($sessionId);
        if (!$session) return false;

        $current = $session['state'];
        $merged = array_merge($current, $state);

        $this->db->where('id', $sessionId)->update('agent_sessions', [
            'state' => json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Get conversation history formatted for LLM
     */
    public function conversationHistory(string $sessionId, int $limit = 20): array
    {
        $session = $this->load($sessionId);
        if (!$session) return [];

        $history = $session['history'];
        if ($limit > 0 && count($history) > $limit) {
            $history = array_slice($history, -$limit);
        }

        return array_map(fn($m) => [
            'role' => $m['role'],
            'content' => $m['content'],
        ], $history);
    }

    /**
     * List sessions for a user
     */
    public function listForUser(int $userId, int $limit = 20): array
    {
        $rows = $this->db
            ->order_by('updated_at', 'DESC')
            ->limit($limit)
            ->get_where('agent_sessions', ['user_id' => $userId])
            ->result_array();

        return array_map(function ($r) {
            $r['state'] = json_decode((string) $r['state'], true) ?: [];
            $r['metadata'] = json_decode((string) $r['metadata'], true) ?: [];
            unset($r['history']); // Don't return full history in list
            return $r;
        }, $rows);
    }

    /**
     * Delete a session
     */
    public function delete(string $sessionId): bool
    {
        $this->db->delete('agent_sessions', ['id' => $sessionId]);
        $this->auditLog('SESSION_DELETED', ['sessionId' => $sessionId]);
        return true;
    }

    /**
     * Clean up expired sessions
     */
    public function cleanup(int $maxAge = 86400): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $maxAge);
        $this->db->where('expires_at <', $cutoff)->delete('agent_sessions');
        return $this->db->affected_rows();
    }

    /**
     * Get session statistics
     */
    public function stats(): array
    {
        $total = $this->db->count_all('agent_sessions');
        $active = $this->db->where('expires_at >', gmdate('Y-m-d H:i:s'))->count_all_results('agent_sessions');

        $byAgent = $this->db
            ->select('agent, COUNT(*) as count')
            ->group_by('agent')
            ->get('agent_sessions')
            ->result_array();

        return [
            'total' => $total,
            'active' => $active,
            'byAgent' => array_column($byAgent, 'count', 'agent'),
        ];
    }

    private function isExpired(array $session): bool
    {
        return strtotime((string) $session['expires_at']) < time();
    }

    private function ensureSchema(): void
    {
        if (!$this->db->table_exists('agent_sessions')) {
            $this->db->query("CREATE TABLE IF NOT EXISTS agent_sessions (
                id VARCHAR(64) PRIMARY KEY,
                user_id INT NOT NULL,
                agent VARCHAR(64) NOT NULL,
                state TEXT,
                history LONGTEXT,
                metadata TEXT,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                message_count INT DEFAULT 0,
                token_count INT DEFAULT 0,
                INDEX idx_user_agent (user_id, agent),
                INDEX idx_expires (expires_at)
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

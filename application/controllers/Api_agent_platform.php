<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Cloudflare Agent Platform API
 * 
 * Provides endpoints for:
 * - Agent execution with session management
 * - Tool execution through MCP registry
 * - Workflow management
 * - Observability data
 */
class Api_agent_platform extends Api_controller
{
    /**
     * Execute an agent with session management
     * POST /api/agent-platform/execute
     */
    public function execute()
    {
        $user = $this->requireLogin();
        if (!$user) return;
        
        $body = $this->jsonBody();
        $agent = trim((string)($body['agent'] ?? ''));
        $instruction = trim((string)($body['instruction'] ?? ''));
        $sessionId = $body['session_id'] ?? null;
        $facts = $body['facts'] ?? [];
        
        if (empty($agent) || empty($instruction)) {
            return $this->jsonError('agent and instruction are required', 400);
        }
        
        if (mb_strlen($instruction) > 4000) {
            return $this->jsonError('instruction is too long (max 4000 chars)', 422);
        }
        
        try {
            $result = $this->platform->cloudflare->executeAgent(
                $agent,
                $instruction,
                (int)$user['id'],
                [
                    'session_id' => $sessionId,
                    'facts' => $facts,
                ]
            );
            
            $this->json($result, !empty($result['ok']) ? 200 : 500);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Execute an MCP tool
     * POST /api/agent-platform/tool
     */
    public function tool()
    {
        $user = $this->requireLogin();
        if (!$user) return;
        
        $body = $this->jsonBody();
        $tool = trim((string)($body['tool'] ?? ''));
        $arguments = $body['arguments'] ?? [];
        
        if (empty($tool)) {
            return $this->jsonError('tool is required', 400);
        }
        
        try {
            $result = $this->platform->cloudflare->executeTool(
                $tool,
                $arguments,
                [
                    'userId' => (int)$user['id'],
                    'agent' => $body['agent'] ?? 'api',
                ]
            );
            
            $this->json($result, !empty($result['ok']) ? 200 : 500);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Start a workflow
     * POST /api/agent-platform/workflow
     */
    public function workflow()
    {
        $user = $this->requireLogin();
        if (!$user) return;
        
        $body = $this->jsonBody();
        $type = trim((string)($body['type'] ?? ''));
        $params = $body['params'] ?? [];
        $options = $body['options'] ?? [];
        
        if (empty($type)) {
            return $this->jsonError('workflow type is required', 400);
        }
        
        try {
            $options['user_id'] = (int)$user['id'];
            $result = $this->platform->cloudflare->startWorkflow($type, $params, $options);
            
            $this->json($result, !empty($result['ok']) ? 201 : 500);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Get workflow status
     * GET /api/agent-platform/workflow/:id
     */
    public function workflow_status(string $id)
    {
        $user = $this->requireLogin();
        if (!$user) return;
        
        try {
            $workflow = $this->platform->cloudflare->workflowEngine()->load($id);
            
            if (!$workflow) {
                return $this->jsonError('Workflow not found', 404);
            }
            
            $this->json(['ok' => true, 'workflow' => $workflow]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * List user sessions
     * GET /api/agent-platform/sessions
     */
    public function sessions()
    {
        $user = $this->requireLogin();
        if (!$user) return;
        
        try {
            $sessions = $this->platform->cloudflare->sessionManager()->listForUser(
                (int)$user['id'],
                50
            );
            
            $this->json(['ok' => true, 'sessions' => $sessions]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Get observability dashboard data
     * GET /api/agent-platform/observability
     */
    public function observability()
    {
        if (!$this->requirePermission('admin.analytics.view')) return;
        
        try {
            $dashboard = $this->platform->cloudflare->observability()->dashboard();
            $this->json(['ok' => true, 'dashboard' => $dashboard]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Get platform status
     * GET /api/agent-platform/status
     */
    public function status()
    {
        try {
            $status = $this->platform->cloudflare->status();
            $this->json(['ok' => true, 'status' => $status]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * List available tools
     * GET /api/agent-platform/tools
     */
    public function tools()
    {
        $user = $this->requireLogin();
        if (!$user) return;
        
        try {
            $categories = $this->platform->cloudflare->toolRegistry()->categories();
            $this->json(['ok' => true, 'tools' => $categories]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * List available agents
     * GET /api/agent-platform/agents
     */
    public function agents()
    {
        $user = $this->requireLogin();
        if (!$user) return;
        
        try {
            $agents = $this->platform->cloudflare->communicationBus()->discoverAgents();
            $this->json(['ok' => true, 'agents' => $agents]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}

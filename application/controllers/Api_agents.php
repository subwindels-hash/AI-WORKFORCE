<?php
defined('BASEPATH') or exit('No direct script access allowed');

/** Controlled application gateway to the AI agent platform. */
class Api_agents extends Api_controller
{
    public function status()
    {
        if (!$this->requirePermission('admin.analytics.view', false)) return;
        $agents = $this->platform->agents->agents();
        $llm = \AIWorkforce\ApiProviders::publicStatus('llm');
        $agentList = [];
        foreach ($agents as $name => $agent) {
            $agentList[$name] = [
                'name' => $agent->name(),
                'tools' => $agent->tools(),
                'model' => class_exists(\AIWorkforce\Agents\EnhancedSpecialistAgent::class)
                    ? \AIWorkforce\Agents\EnhancedSpecialistAgent::modelFor($name)
                    : null,
            ];
        }
        $this->json([
            'agents' => $agentList,
            'llm' => $llm,
            'provider' => $llm['driver'],
            'models' => class_exists(\AIWorkforce\Agents\EnhancedSpecialistAgent::class)
                ? \AIWorkforce\Agents\EnhancedSpecialistAgent::allRoleModels()
                : [],
        ]);
    }

    public function dispatch()
    {
        $user = $this->requirePermission('system.authenticated');
        if (!$user) return;

        $body = $this->jsonBody();
        $agent = trim((string) ($body['agent'] ?? ''));
        $instruction = trim((string) ($body['instruction'] ?? ''));
        if ($agent === '' || $instruction === '') {
            return $this->jsonError('agent and instruction are required');
        }
        if (mb_strlen($instruction) > 4000) {
            return $this->jsonError('instruction is too long', 422);
        }

        $this->respondWithDispatch(
            $user,
            $agent,
            $instruction,
            $this->normalizedConversation($body['conversation'] ?? null),
            is_array($body['facts'] ?? null) ? $body['facts'] : []
        );
    }

    /** Multipart upload endpoint for analysing a user file with a specialist agent. */
    public function analyze_upload()
    {
        $user = $this->requirePermission('system.authenticated');
        if (!$user) return;

        $agent = trim((string) ($this->input->post('agent') ?? ''));
        $instruction = trim((string) ($this->input->post('instruction') ?? ''));
        if ($agent === '') {
            return $this->jsonError('agent is required');
        }
        if ($instruction === '') {
            $instruction = 'Analyze this uploaded file. Explain what it contains, the important points, and any limitations in the extraction.';
        }
        if (mb_strlen($instruction) > 4000) {
            return $this->jsonError('instruction is too long', 422);
        }
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            return $this->jsonError('file is required');
        }

        try {
            $analysis = (new \AIWorkforce\WorkforceFileAnalyzer())->analyze($_FILES['file']);
            $this->AIWorkforce_model->audit->emit(
                'WORKFORCE_UPLOAD_ANALYZED',
                'Workforce upload analysed by ' . $agent,
                [
                    'userId' => (int) $user['id'],
                    'agent' => $agent,
                    'file' => $analysis['attachment'] ?? [],
                ],
                'user'
            );
            $conversation = $this->normalizedConversation($this->decodeConversationPost($this->input->post('conversation')));
            $this->respondWithDispatch(
                $user,
                $agent,
                $instruction,
                $conversation,
                is_array($analysis['facts'] ?? null) ? $analysis['facts'] : [],
                [
                    'attachment' => $analysis['attachment'] ?? null,
                    'contextMessage' => (string) ($analysis['contextMessage'] ?? ''),
                    'fileFacts' => is_array($analysis['facts'] ?? null) ? $analysis['facts'] : [],
                    'warnings' => array_values(array_filter(array_map('strval', (array) ($analysis['warnings'] ?? [])))),
                ]
            );
        } catch (\Throwable $e) {
            $status = $e->getCode();
            if (!is_int($status) || $status < 400 || $status > 599) $status = 422;
            $this->jsonError($e->getMessage(), $status);
        }
    }

    /** @param mixed $raw @return array<int,array{role:string,content:string}> */
    private function normalizedConversation($raw): array
    {
        if (!is_array($raw)) return [];
        $history = [];
        foreach (array_slice($raw, -10) as $message) {
            if (!is_array($message)) continue;
            $role = trim((string) ($message['role'] ?? ''));
            $content = trim((string) ($message['content'] ?? $message['text'] ?? ''));
            if ($content === '' || !in_array($role, ['user', 'assistant', 'system'], true)) continue;
            $history[] = ['role' => $role, 'content' => $content];
        }
        return $history;
    }

    /** @return mixed */
    private function decodeConversationPost($raw)
    {
        if (is_array($raw)) return $raw;
        if (!is_string($raw) || trim($raw) === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $user @param array<int,array{role:string,content:string}> $conversation @param array<string,mixed> $facts @param array<string,mixed> $extra */
    private function respondWithDispatch(array $user, string $agent, string $instruction, array $conversation, array $facts = [], array $extra = []): void
    {
        $allowed = $this->platform->agents->agents();
        if (!isset($allowed[$agent])) {
            $this->jsonError('agent unavailable', 404);
            return;
        }

        $context = [
            'userId' => (int) $user['id'],
            'permissions' => ['ai.use'],
        ];
        if (!empty($conversation)) {
            $context['conversation'] = $conversation;
        }

        $result = $this->platform->agents->dispatch($agent, [
            'instruction' => $instruction,
            'facts' => $facts,
        ], $context);

        if (!empty($extra)) {
            if (!is_array($result)) $result = ['result' => $result];
            foreach ($extra as $key => $value) {
                $result[$key] = $value;
            }
        }

        $this->json($result, !empty($result['ok']) ? 200 : 503);
    }
}

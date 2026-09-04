<?php
defined('BASEPATH') or exit('No direct script access allowed');
/** Controlled application gateway to the Cloudflare agent runtime. */
class Api_agents extends Api_controller
{
    public function status()
    {
        if (!$this->requirePermission('admin.analytics.view', false)) return;
        $agents = $this->platform->agents->agents();
        $agentList = [];
        foreach ($agents as $name => $agent) {
            $agentList[$name] = [
                'name' => $agent->name(),
                'tools' => $agent->tools(),
                'model' => class_exists(\AIWorkforce\Agents\EnhancedCloudflareAgent::class)
                    ? \AIWorkforce\Agents\EnhancedCloudflareAgent::modelFor($name)
                    : null,
            ];
        }
        $this->json([
            'agents' => $agentList,
            'cloudflare' => \AIWorkforce\ApiProviders::publicStatus('llm'),
            'provider' => 'cloudflare_workers_ai',
            'models' => class_exists(\AIWorkforce\Agents\EnhancedCloudflareAgent::class)
                ? \AIWorkforce\Agents\EnhancedCloudflareAgent::allRoleModels()
                : [],
        ]);
    }

    public function cloudflare_status()
    {
        if (!$this->requirePermission('admin.analytics.view', false)) return;
        $cfg = \AIWorkforce\ApiProviders::resolve('llm');
        if (!is_array($cfg) || ($cfg['driver'] ?? '') !== 'cloudflare_workers_ai') {
            $this->json(['configured' => false, 'driver' => $cfg['driver'] ?? 'none']);
            return;
        }

        // Test Cloudflare connectivity
        $provider = new \AIWorkforce\Providers\CloudflareProvider([
            'account_id' => $cfg['account_id'] ?? '',
            'token' => $cfg['secrets']['token'] ?? '',
            'gateway' => $cfg['extra']['gateway'] ?? null,
            'base_url' => $cfg['base_url'] ?? '',
            'timeout' => 15,
        ]);

        $status = $provider->status();
        $testResult = null;
        if ($provider->isConfigured()) {
            $start = microtime(true);
            $test = $provider->generateText('Hello, respond with one word.', ['max_tokens' => 10]);
            $latency = round((microtime(true) - $start) * 1000);
            $testResult = [
                'ok' => $test && !isset($test['error']),
                'latencyMs' => $latency,
                'response' => isset($test['error']) ? null : mb_substr($test['result']['response'] ?? $test['response'] ?? '', 0, 100),
                'error' => $test['error'] ?? null,
            ];
        }

        $this->json([
            'configured' => true,
            'provider' => $status,
            'test' => $testResult,
            'availableModels' => $provider->getAvailableModels(),
        ]);
    }

    public function dispatch()
    {
        $user=$this->requirePermission('system.authenticated'); if(!$user) return;
        $body=$this->jsonBody(); $agent=trim((string)($body['agent']??'')); $instruction=trim((string)($body['instruction']??''));
        if($agent===''||$instruction==='') return $this->jsonError('agent and instruction are required');
        if(mb_strlen($instruction)>4000) return $this->jsonError('instruction is too long',422);
        $allowed=$this->platform->agents->agents(); if(!isset($allowed[$agent])) return $this->jsonError('agent unavailable',404);
        $permissions=['ai.use'];
        $context = ['userId'=>(int)$user['id'],'permissions'=>$permissions];

        // Include conversation history if provided
        if (!empty($body['conversation']) && is_array($body['conversation'])) {
            $context['conversation'] = array_slice($body['conversation'], -10);
        }

        $result=$this->platform->agents->dispatch($agent,['instruction'=>$instruction,'facts'=>is_array($body['facts']??null)?$body['facts']:[]],$context);
        $this->json($result, !empty($result['ok'])?200:503);
    }
}

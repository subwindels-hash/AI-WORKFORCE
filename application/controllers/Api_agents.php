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
                'model' => class_exists(\AIWorkforce\Agents\EnhancedCloudflareAgent::class)
                    ? \AIWorkforce\Agents\EnhancedCloudflareAgent::modelFor($name)
                    : null,
            ];
        }
        $this->json([
            'agents' => $agentList,
            'llm' => $llm,
            'provider' => $llm['driver'],
            'models' => class_exists(\AIWorkforce\Agents\EnhancedCloudflareAgent::class)
                ? \AIWorkforce\Agents\EnhancedCloudflareAgent::allRoleModels()
                : [],
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

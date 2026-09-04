<?php
defined('BASEPATH') or exit('No direct script access allowed');
/** Controlled application gateway to the Cloudflare agent runtime. */
class Api_agents extends Api_controller
{
    public function status()
    {
        if (!$this->requirePermission('admin.analytics.view', false)) return;
        $this->json(['agents'=>array_map(fn($a)=>['name'=>$a->name(),'tools'=>$a->tools()],$this->platform->agents->agents()),'cloudflare'=>\AIWorkforce\ApiProviders::publicStatus('llm')]);
    }
    public function dispatch()
    {
        $user=$this->requirePermission('system.authenticated'); if(!$user) return;
        $body=$this->jsonBody(); $agent=trim((string)($body['agent']??'')); $instruction=trim((string)($body['instruction']??''));
        if($agent===''||$instruction==='') return $this->jsonError('agent and instruction are required');
        if(mb_strlen($instruction)>4000) return $this->jsonError('instruction is too long',422);
        $allowed=$this->platform->agents->agents(); if(!isset($allowed[$agent])) return $this->jsonError('agent unavailable',404);
        $permissions=['ai.use'];
        $result=$this->platform->agents->dispatch($agent,['instruction'=>$instruction,'facts'=>is_array($body['facts']??null)?$body['facts']:[]],['userId'=>(int)$user['id'],'permissions'=>$permissions]);
        $this->json($result, !empty($result['ok'])?200:503);
    }
}

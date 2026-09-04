<?php
namespace AIWorkforce\Agents;

/**
 * Application-control-plane boundary for a future Cloudflare Agents runtime.
 * Cloudflare may host durable execution, sessions, workflows, MCP and tools;
 * this backend remains authoritative for identity, permissions, approvals and
 * audit. No agent receives a broker or purchase capability by default.
 */
interface SpecialistAgent
{
    public function name(): string;
    /** @return array<string> */ public function tools(): array;
    /** @return array<string,mixed> */ public function handle(array $request, array $context): array;
}

final class AgentOrchestrator
{
    /** @var array<string,SpecialistAgent> */ private array $agents = [];
    /** @var callable|null */ private $audit;
    /** @var callable|null */ private $approval;
    private const TOOL_OWNER = [
        'crypto.getPrice'=>'market', 'forex.getRate'=>'market', 'sports.getFixtures'=>'sports',
        'sports.getMatchStats'=>'sports', 'lottery.getResults'=>'lottery', 'lottery.generateCombinations'=>'lottery',
        'lottery.purchaseTicket'=>'lottery_purchase', 'broker.getAccount'=>'trading', 'broker.getPositions'=>'trading',
        'broker.submitTrade'=>'trading_execution', 'language.analyzePronunciation'=>'language', 'video.create'=>'video',
    ];
    public function __construct(?callable $audit=null, ?callable $approval=null) { $this->audit=$audit; $this->approval=$approval; }
    public function register(SpecialistAgent $agent): void { $this->agents[$agent->name()]=$agent; }
    /** @return array<string,SpecialistAgent> */ public function agents(): array { return $this->agents; }
    public function dispatch(string $agent, array $request, array $context=[]): array
    {
        if (!isset($this->agents[$agent])) return ['ok'=>false,'error'=>'agent unavailable'];
        $id='agt_'.bin2hex(random_bytes(8)); $context['executionId']=$id;
        try { $result=$this->agents[$agent]->handle($request,$context); $this->record('AGENT_COMPLETED',$id,$agent,$result); return ['ok'=>true,'executionId'=>$id,'agent'=>$agent,'result'=>$result]; }
        catch(\Throwable $e){ $this->record('AGENT_FAILED',$id,$agent,['error'=>$e->getMessage()]); return ['ok'=>false,'executionId'=>$id,'error'=>'agent execution failed']; }
    }
    /** Tool calls are allowlisted and sensitive actions become approval requests. */
    public function authorizeTool(string $agent,string $tool,array $context=[]): array
    {
        $owner=self::TOOL_OWNER[$tool]??null; if($owner===null) return ['allowed'=>false,'reason'=>'unknown tool'];
        if($tool==='broker.submitTrade'||$tool==='lottery.purchaseTicket'){
            $approval=$this->approval ? ($this->approval)($tool,$context) : ['required'=>true];
            $this->record('AGENT_APPROVAL_REQUESTED',$context['executionId']??null,$agent,['tool'=>$tool]);
            return ['allowed'=>false,'approvalRequired'=>true,'approval'=>$approval];
        }
        $allowed=in_array($owner, (array)($context['permissions']??[]),true) || in_array('*',(array)($context['permissions']??[]),true);
        return ['allowed'=>$allowed,'reason'=>$allowed?null:'agent tool permission denied'];
    }
    private function record(string $type,?string $id,string $agent,array $data):void { if($this->audit) try{($this->audit)($type,$id,$agent,$data);}catch(\Throwable $e){} }
}

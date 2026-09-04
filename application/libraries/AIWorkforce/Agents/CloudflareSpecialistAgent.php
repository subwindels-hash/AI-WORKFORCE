<?php
namespace AIWorkforce\Agents;

use AIWorkforce\ApiProviders;

/** Cloudflare Workers AI-backed specialist. Domain facts must be supplied by
 * the core services; the model is never treated as a market/sports authority. */
final class CloudflareSpecialistAgent implements SpecialistAgent
{
    public function __construct(private string $role, private array $allowedTools = []) {}
    public function name(): string { return $this->role; }
    public function tools(): array { return $this->allowedTools; }
    public function handle(array $request, array $context): array
    {
        $cfg=ApiProviders::resolve('llm') ?: ApiProviders::resolve('language_ai');
        if (!is_array($cfg)) return ['status'=>'UNAVAILABLE','reason'=>'Cloudflare/LLM provider is not configured'];
        $facts=json_encode($request['facts']??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $system="You are the {$this->role} specialist in WINDELS. Use only supplied facts. Never invent live prices, sports results, lottery results, broker state or user records. Return concise structured JSON when possible. Trading is analysis/proposal only; never authorize execution.";
        $answer=ApiProviders::openaiChat($cfg,[['role'=>'system','content'=>$system],['role'=>'user','content'=>(string)($request['instruction']??'Analyze the supplied facts.')."\nFACTS:\n".$facts]],700);
        return $answer===null?['status'=>'UNAVAILABLE','reason'=>'Cloudflare model request failed']:['status'=>'COMPLETED','role'=>$this->role,'answer'=>$answer,'tools'=>$this->allowedTools];
    }
}

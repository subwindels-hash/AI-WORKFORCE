<?php
namespace Aegis\Agents;

/**
 * Phase 6 fundamentals boundary. It abstains until a licensed, attributable
 * fundamentals feed is configured; price action is never relabelled as a
 * fundamental signal.
 */
class FundamentalsAgent
{
    use AgentHelperTrait;
    public const ID = 'fundamentals';
    public function applicable(array $ctx): bool { return true; }
    public function analyze(array $ctx): array
    {
        return [
            'agent' => self::ID, 'title' => 'Fundamentals Intelligence Agent',
            'generatedAt' => $ctx['now'], 'dataQuality' => 0.0,
            'dataLimitations' => ['No licensed fundamentals provider configured'],
            'warnings' => ['Fundamentals unavailable — this agent abstains and cannot affect consensus'],
            'vote' => ['directionalScore' => 0.0, 'signal' => 'NEUTRAL', 'weight' => self::WEIGHTS['sentiment'], 'votes' => false, 'reason' => 'No attributable fundamentals feed configured — abstaining'],
            'earnings' => ['available' => false, 'reason' => 'No earnings/calendar feed configured'],
            'macro' => ['available' => false, 'reason' => 'No macroeconomic release feed configured'],
            'valuation' => ['available' => false, 'reason' => 'No issuer fundamentals feed configured'],
            'provenance' => ['source' => null, 'licensed' => false],
        ];
    }
}

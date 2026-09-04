# Multiplier Intelligence + Cloudflare Agent Platform Integration

## Overview

The Multiplier Intelligence module is fully integrated with the Cloudflare AI Agent Platform and enriched with Sports Intelligence data. This document explains how the three systems work together.

---

## Architecture Diagram

```
┌────────────────────────────────────────────────────────────────────────────┐
│                       CLOUDFLARE AGENT PLATFORM                            │
│                                                                            │
│  ┌─────────────────┐  ┌──────────────────┐  ┌─────────────────────────┐  │
│  │ AgentOrchestrator│  │  McpToolRegistry │  │     ModelRouter         │  │
│  │ (Agent dispatch) │  │  (Tool gateway)  │  │  (LLM model gateway)   │  │
│  │                  │  │                  │  │                         │  │
│  │ • MarketAnalyst  │  │ • crypto.*       │  │ • Cloudflare Workers AI │  │
│  │ • RiskManager    │  │ • forex.*        │  │ • OpenAI Compatible     │  │
│  │ • SignalGen      │  │ • sports.*       │  │ • Multi-model failover  │  │
│  │ • PatternDetect  │  │ • lottery.*      │  │ • Rate limiting         │  │
│  │ • DataAggregator │  │ • broker.*       │  │ • Usage tracking        │  │
│  │ • Validator      │  │ • language.*     │  │                         │  │
│  │ • MultiplierAgent│  │ • multiplier.* ◄─┼──┤ NEW: Multiplier tools  │  │
│  └────────┬─────────┘  └────────┬─────────┘  └────────────┬────────────┘  │
│           │                      │                         │               │
└───────────┼──────────────────────┼─────────────────────────┼───────────────┘
            │                      │                         │
            ▼                      ▼                         ▼
┌────────────────────────────────────────────────────────────────────────────┐
│              MULTIPLIER INTELLIGENCE INTEGRATION LAYER                      │
│                                                                            │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │ MultiplierPlatformIntegration                                        │  │
│  │ • Wires everything together at platform bootstrap                    │  │
│  │ • Registers agent with orchestrator                                  │  │
│  │ • Registers 6 multiplier.* tools                                     │  │
│  │ • Initializes sports enrichment                                      │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
│                                                                            │
│  ┌─────────────────────┐  ┌───────────────────┐  ┌─────────────────────┐  │
│  │MultiplierSpecialist │  │MultiplierCloudflare│  │SportsBettingEnrich- │  │
│  │Agent                │  │Bridge              │  │mentProvider          │  │
│  │                     │  │                    │  │                      │  │
│  │• Implements         │  │• Wraps 9 agents    │  │• Connects to Sports  │  │
│  │  SpecialistAgent    │  │• LLM enhancement   │  │  Intelligence        │  │
│  │• Dispatchable via   │  │• Tool handlers     │  │• (api-football,      │  │
│  │  CommunicationBus   │  │• Signal generation │  │   thesportsdb,       │  │
│  │• Cross-module       │  │• Ensemble reasoning│  │   sportmonks)        │  │
│  │  collaboration      │  │                    │  │• Market sentiment    │  │
│  └─────────────────────┘  └───────────────────┘  │• Event timing        │  │
│                                                    │• Max 15% influence   │  │
│                                                    └─────────────────────┘  │
└────────────────────────────────────────────────────────────────────────────┘
            │
            ▼
┌────────────────────────────────────────────────────────────────────────────┐
│              MULTIPLIER INTELLIGENCE ENGINE                                 │
│                                                                            │
│  ┌─────────────────────────────────────────────────────────────────────┐  │
│  │ 9 Specialist AI Agents (statistical analysis)                       │  │
│  │                                                                     │  │
│  │ 1. Historical Analysis    → Distribution patterns                   │  │
│  │ 2. Pattern Detection      → Streaks, alternation                    │  │
│  │ 3. Probability Analysis   → Empirical distributions                 │  │
│  │ 4. Sequence Analysis      → Moving averages, trends                 │  │
│  │ 5. Anomaly Detection      → Z-score outliers                        │  │
│  │ 6. Risk Assessment        → Multi-factor risk scoring               │  │
│  │ 7. Validation Agent       → Prediction accuracy tracking            │  │
│  │ 8. Performance Tracking   → Model performance over time             │  │
│  │ 9. Executive Prediction   → Weighted ensemble (stat + LLM)          │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
│                                                                            │
│  ┌─────────────────────────────────────────────────────────────────────┐  │
│  │ CrashGameProvider Interface                                         │  │
│  │ • SimulationProvider (demo, geometric distribution, 2% house edge) │  │
│  │ • AviatorProvider (future: connect to real game API)               │  │
│  └─────────────────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────────┘
```

---

## Integration Points

### 1. MCP Tool Registration (6 tools)

All 6 multiplier tools are now available in `McpToolRegistry` under the `multiplier` category:

| Tool | Description |
|------|-------------|
| `multiplier.getCurrentMultiplier` | Get live multiplier value |
| `multiplier.getHistory` | Get historical round data |
| `multiplier.generateSignal` | Generate AI prediction (with LLM enhancement) |
| `multiplier.getAccuracy` | Get accuracy statistics |
| `multiplier.listAgents` | List all 9 specialist agents |
| `multiplier.analyzeRound` | Run specific agent analysis |

**Who can use these tools:**
- ANY Cloudflare agent can call these tools
- LLM agents can use them for function calling
- Workflow engine can orchestrate them
- Cross-module collaboration (e.g., sports agent + multiplier agent)

### 2. Specialist Agent Registration

The `MultiplierSpecialistAgent` is registered with the `AgentOrchestrator`:

```php
$agent = new MultiplierSpecialistAgent(
    'MultiplierAnalyst',
    new SimulationProvider(),
    $sportsEnrichment,
    $modelRouter
);

// Now dispatchable via:
$platform->executeAgent('MultiplierAnalyst', 'Generate next signal', $userId);
$platform->routeRequest('What multiplier should I expect?', $userId);
```

**Capabilities:**
- Can be dispatched via `AgentCommunicationBus`
- Can collaborate with other agents (debates, cross-analysis)
- Can be included in workflows
- Responds to natural language queries about crash games

### 3. LLM Enhancement via ModelRouter

Each of the 9 specialist agents can optionally use LLM reasoning:

```
Statistical Analysis (70%) + LLM Reasoning (30%) = Enhanced Prediction
```

**How it works:**
1. Agent runs its statistical analysis
2. Statistical result + features sent to LLM via ModelRouter
3. LLM provides adjusted estimate and reasoning
4. Results blended (70/30 statistical/LLM)
5. Final prediction includes both sources

**LLM Enhancement Flow:**
```
Agent → Statistical Result → LLM Prompt → LLM Response → Blended Prediction
         (70% weight)                                      (100% output)
```

### 4. Sports Intelligence Enrichment

Sports data from api-football, thesportsdb, and sportmonks enriches multiplier predictions:

**Enrichment Signals:**
- **Market Sentiment** — Sports betting odds indicate risk appetite
- **Event Activity** — Live sports events correlate with crash game volume
- **Major Events** — Premier League/Champions League days = peak traffic
- **Volatility** — Odds variability indicates market uncertainty

**Influence Cap:** Max 15% adjustment to preserve statistical integrity

```
Base Prediction (from 9 agents)
     ↓
Sports Enrichment (±15% max)
     ↓
Enriched Prediction
     ↓
LLM Enhancement (optional)
     ↓
Final Signal
```

---

## Does Sports Intelligence Support Crash Games?

**Short answer:** No — but that's by design, and it's actually beneficial.

**Long answer:** The three sports providers (api-football, thesportsdb, sportmonks) are designed for **traditional sports** — football matches, basketball games, tennis, etc. They provide:
- Fixtures (upcoming matches)
- Live scores
- Historical results
- Betting odds
- Player/team statistics

They do NOT provide crash game data because crash games are **casino/betting products**, not sports events.

**However**, the Sports Intelligence data IS useful for multiplier predictions because:

1. **Betting Market Sentiment**: When sports bettors are aggressive (high confidence in favorites), crash game players tend to be more risk-seeking too. This shifts the distribution slightly.

2. **Event Timing**: During major sporting events (World Cup, Champions League), crash game traffic spikes. Higher traffic = more rounds = different statistical properties.

3. **Market Correlation**: Sports betting odds reflect overall gambling market confidence. This is a weak but real signal.

**The `SportsBettingEnrichmentProvider` bridges this gap** — it reads from Sports Intelligence and extracts market signals that can slightly adjust multiplier predictions (max 15% influence).

---

## Usage Examples

### Example 1: Generate Cloudflare-Enhanced Signal

```php
use AIWorkforce\MultiplierIntelligence\MultiplierPlatformIntegration;

$integration = new MultiplierPlatformIntegration($platform);
$integration->register();

$signal = $integration->generateEnhancedSignal();
// Result includes:
// - Statistical prediction from 9 agents
// - Sports enrichment (if available)
// - LLM enhancement (if Cloudflare configured)
```

### Example 2: Dispatch Multiplier Agent via CommunicationBus

```php
// Any agent can now dispatch to the Multiplier agent
$result = $platform->executeAgent(
    'MultiplierAnalyst',
    'What is the predicted next multiplier?',
    $userId
);
```

### Example 3: Call Multiplier Tools from Other Agents

```php
// From a workflow or another agent:
$multiplier = $platform->executeTool('multiplier.getCurrentMultiplier');
$history = $platform->executeTool('multiplier.getHistory', ['limit' => 50]);
$signal = $platform->executeTool('multiplier.generateSignal');
$accuracy = $platform->executeTool('multiplier.getAccuracy', ['window' => 100]);
```

### Example 4: Cross-Module Analysis

```php
// Sports agent + Multiplier agent collaborate
$sportsData = $platform->executeTool('sports.getFixtures', ['limit' => 5]);
$multiplierSignal = $platform->executeTool('multiplier.generateSignal');

// LLM agent analyzes both
$analysis = $platform->modelRouter()->complete([
    ['role' => 'user', 'content' => "Sports fixtures: " . json_encode($sportsData) . 
     "\nMultiplier signal: " . json_encode($multiplierSignal) .
     "\nIs there a correlation? Should the multiplier prediction be adjusted?"]
]);
```

---

## Data Flow

```
┌──────────────┐     ┌──────────────┐     ┌──────────────────┐
│ api-football │     │ thesportsdb  │     │   sportmonks     │
│              │     │              │     │                  │
│ Fixtures     │     │ Fixtures     │     │ Fixtures         │
│ Odds         │     │ Results      │     │ Odds             │
│ Results      │     │ Standings    │     │ Live Scores      │
└──────┬───────┘     └──────┬───────┘     └────────┬─────────┘
       │                    │                       │
       └────────────────────┼───────────────────────┘
                            │
                            ▼
              ┌──────────────────────────┐
              │ Sports Intelligence      │
              │ (SportsIntelligence.php) │
              │                          │
              │ • Fixtures, odds, results│
              │ • Health monitoring      │
              │ • Data normalization     │
              └────────────┬─────────────┘
                           │
                           ▼
              ┌──────────────────────────┐
              │ SportsBettingEnrichment  │
              │ Provider                 │
              │                          │
              │ • Market sentiment       │
              │ • Event timing           │
              │ • Volatility signals     │
              │ • Max 15% influence      │
              └────────────┬─────────────┘
                           │
                           ▼
┌──────────────┐     ┌──────────────────────────────┐     ┌─────────────────┐
│ Simulation   │     │ MultiplierIntelligenceEngine │     │  ModelRouter    │
│ Provider     │────▶│                              │────▶│  (Cloudflare    │
│              │     │ 9 Specialist Agents          │     │   Workers AI)   │
│ • Rounds     │     │ • Historical                 │     │                 │
│ • Multipliers│     │ • Pattern                    │     │ • LLM reasoning │
│ • History    │     │ • Probability                │     │ • Enhancement   │
│              │     │ • Sequence                   │     │ • 70/30 blend   │
│              │     │ • Anomaly                    │     │                 │
│              │     │ • Risk                       │     │                 │
│              │     │ • Validation                 │     │                 │
│              │     │ • Performance                │     │                 │
│              │     │ • Executive                  │     │                 │
└──────────────┘     └──────────────┬───────────────┘     └─────────────────┘
                                    │
                                    ▼
              ┌──────────────────────────────┐
              │ Final Signal                 │
              │                              │
              │ • predictedMultiplier        │
              │ • confidence                 │
              │ • risk level                 │
              │ • agent breakdown            │
              │ • sports_enrichment          │
              │ • cloudflare_enhanced        │
              │ • disclaimer                 │
              └──────────────────────────────┘
```

---

## Configuration

### Enable/Disable LLM Enhancement

```php
$bridge = new MultiplierCloudflareBridge($modelRouter);
$bridge->enableLLMEnhancement(true);  // Enable LLM reasoning
$bridge->enableLLMEnhancement(false); // Disable (pure statistical)
```

### Set Sports Enrichment Weight

```php
$enrichment = new SportsBettingEnrichmentProvider($sportsIntel);
$enrichment->setEnrichmentWeight(0.15); // 15% max influence (default)
$enrichment->setEnrichmentWeight(0.0);  // Disable enrichment entirely
$enrichment->setEnrichmentWeight(0.3);  // 30% influence (aggressive)
```

### Integration Status

```php
$integration = new MultiplierPlatformIntegration($platform);
$integration->register();
$status = $integration->status();

// Returns:
// [
//   'registered' => true,
//   'agent_available' => true,
//   'bridge_available' => true,
//   'enrichment_available' => true,
//   'llm_enhancement' => true,
//   'tools_registered' => 6,
//   'agents_available' => 9,
// ]
```

---

## Agent Ownership Mapping

The `AgentOrchestrator::TOOL_OWNER` now includes multiplier tools:

```php
'multiplier.getCurrentMultiplier' => 'multiplier',
'multiplier.getHistory' => 'multiplier',
'multiplier.generateSignal' => 'multiplier',
'multiplier.getAccuracy' => 'multiplier',
'multiplier.listAgents' => 'multiplier',
'multiplier.analyzeRound' => 'multiplier',
```

Permission required: `'multiplier'` in context permissions.

---

## Safety & Ethics

1. **No guaranteed predictions** — Every signal includes a disclaimer
2. **Transparency** — All predictions recorded and validated
3. **Bounded enrichment** — Sports data capped at 15% influence
4. **Statistical dominance** — 70%+ weight on pure statistical analysis
5. **Audit trail** — All LLM calls logged via ModelRouter
6. **Approval gates** — No approval required for analysis (read-only)
7. **Responsible use** — Educational purpose clearly communicated

---

## Future Enhancements

- [ ] Real Aviator provider adapter (connect to actual game API)
- [ ] WebSocket streaming of Cloudflare-enhanced signals
- [ ] Multi-game support (JetX, Spaceman, etc.)
- [ ] Agent debate: Multiplier agents vs Trading risk agents
- [ ] Historical backtesting with sports correlation analysis
- [ ] A/B testing: Statistical-only vs Statistical+LLM
- [ ] Community predictions aggregation

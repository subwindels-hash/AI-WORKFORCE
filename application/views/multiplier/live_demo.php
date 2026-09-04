<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $demo */
?>
<style>
.demo-bg{background:linear-gradient(180deg,#0a0e27 0%,#131836 50%,#0a0e27 100%);min-height:100vh;padding:20px}
.demo-container{max-width:1400px;margin:0 auto}
.demo-header{text-align:center;padding:30px;background:linear-gradient(135deg,#1e1b4b 0%,#312e81 50%,#4c1d95 100%);border-radius:16px;border:2px solid #6366f1;margin-bottom:24px}
.demo-header h1{font-size:32px;font-weight:900;color:#fff;margin:0 0 8px}
.demo-header p{font-size:14px;color:#c7d2fe;margin:0}
.demo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:20px}
.demo-panel{background:rgba(20,25,60,.6);backdrop-filter:blur(10px);border:1px solid #2d3568;border-radius:16px;padding:20px;position:relative;overflow:hidden}
.demo-panel::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,#6366f1,transparent)}
.demo-panel h3{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin:0 0 16px;display:flex;align-items:center;gap:8px}
.demo-stat{background:rgba(15,23,42,.5);border-radius:10px;padding:12px;margin-bottom:8px}
.demo-stat-label{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.1em}
.demo-stat-value{font-size:20px;font-weight:800;color:#e2e8f0;margin-top:4px}
.demo-live{background:linear-gradient(135deg,#dc2626 0%,#ef4444 100%);border-radius:12px;padding:20px;text-align:center;border:2px solid #ef4444;animation:pulse 2s infinite}
.demo-live-value{font-size:64px;font-weight:900;color:#fff;line-height:1}
.demo-live-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.15em;color:#fecaca;margin-top:8px}
.demo-signal{background:linear-gradient(135deg,#059669 0%,#10b981 100%);border-radius:12px;padding:20px;text-align:center;border:2px solid #10b981}
.demo-signal-value{font-size:56px;font-weight:900;color:#fff;line-height:1}
.demo-signal-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.15em;color:#d1fae5;margin-top:8px}
.demo-agents{display:flex;flex-direction:column;gap:8px;max-height:400px;overflow-y:auto}
.demo-agent{background:rgba(15,23,42,.5);border-radius:8px;padding:10px;border:1px solid #1e293b}
.demo-agent-name{font-size:11px;font-weight:600;color:#e2e8f0}
.demo-agent-estimate{font-size:18px;font-weight:800;color:#a5b4fc;margin:4px 0}
.demo-agent-conf{font-size:10px;color:#64748b}
.demo-badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.demo-badge-success{background:#22c55e22;color:#22c55e;border:1px solid #22c55e44}
.demo-badge-warning{background:#f59e0b22;color:#f59e0b;border:1px solid #f59e0b44}
.demo-badge-info{background:#3b82f622;color:#3b82f6;border:1px solid #3b82f644}
.demo-flow{background:rgba(15,23,42,.3);border-radius:12px;padding:16px;margin-top:16px}
.demo-flow-step{display:flex;align-items:center;gap:12px;padding:10px;background:rgba(15,23,42,.5);border-radius:8px;margin-bottom:8px}
.demo-flow-icon{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.demo-flow-text{flex:1}
.demo-flow-title{font-size:12px;font-weight:700;color:#e2e8f0}
.demo-flow-desc{font-size:10px;color:#94a3b8;margin-top:2px}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.4)}50%{box-shadow:0 0 0 20px rgba(239,68,68,0)}}
</style>

<div class="demo-bg">
  <div class="demo-container">
    <!-- Header -->
    <div class="demo-header">
      <h1>🚀 Live Crash Analysis</h1>
      <p>Live crash history + Windels AI Agents + Sports Intelligence — no simulated rounds</p>
    </div>

    <?php if (!empty($demo['error'])): ?>
      <div style="background:#ef444422;border:1px solid #ef4444;border-radius:12px;padding:16px;color:#fca5a5;margin-bottom:20px">
        <strong>Error:</strong> <?= e($demo['error']) ?>
      </div>
    <?php else: ?>

    <!-- Live Round + Signal -->
    <div class="demo-grid" style="grid-template-columns:1fr 1fr">
      <!-- Live Aviator Round -->
      <div class="demo-panel">
        <h3>
          <span style="width:8px;height:8px;border-radius:50%;background:#ef4444;box-shadow:0 0 8px #ef4444"></span>
          Live Crash Round
        </h3>
        <?php $live = $demo['aviator']['live_round'] ?? []; ?>
        <div class="demo-live">
          <div class="demo-live-value"><?= e(number_format($live['currentMultiplier'] ?? 1.0, 2)) ?>x</div>
          <div class="demo-live-label">
            <?= !empty($live['inRound']) ? '● LIVE' : '○ WAITING' ?>
          </div>
        </div>
        <div style="margin-top:16px">
          <div class="demo-stat">
            <div class="demo-stat-label">Round ID</div>
            <div class="demo-stat-value" style="font-size:12px;font-family:monospace"><?= e($live['roundId'] ?? 'N/A') ?></div>
          </div>
          <div class="demo-stat">
            <div class="demo-stat-label">Elapsed</div>
            <div class="demo-stat-value"><?= e(number_format(($live['elapsedMs'] ?? 0) / 1000, 1)) ?>s</div>
          </div>
        </div>
      </div>

      <!-- AI Prediction Signal -->
      <div class="demo-panel">
        <h3>
          <span style="width:8px;height:8px;border-radius:50%;background:#10b981;box-shadow:0 0 8px #10b981"></span>
          AI Prediction Signal
        </h3>
        <?php $sig = $demo['signal'] ?? []; ?>
        <div class="demo-signal">
          <div class="demo-signal-value"><?= e(number_format($sig['predicted'] ?? 2.0, 2)) ?>x</div>
          <div class="demo-signal-label">Predicted Next Multiplier</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px">
          <div class="demo-stat">
            <div class="demo-stat-label">Confidence</div>
            <div class="demo-stat-value" style="color:#22c55e"><?= e(number_format($sig['confidence'] ?? 50, 0)) ?>%</div>
          </div>
          <div class="demo-stat">
            <div class="demo-stat-label">Risk Level</div>
            <div class="demo-stat-value" style="color:<?= strtolower($sig['risk'] ?? 'medium') === 'low' ? '#22c55e' : (strtolower($sig['risk'] ?? '') === 'high' ? '#ef4444' : '#f59e0b') ?>">
              <?= e(strtoupper($sig['risk'] ?? 'MEDIUM')) ?>
            </div>
          </div>
        </div>
        <?php if (!empty($sig['sports_enrichment']['applied'])): ?>
          <div style="margin-top:12px;padding:10px;background:rgba(59,130,246,.1);border:1px solid #3b82f644;border-radius:8px">
            <div style="font-size:10px;color:#3b82f6;font-weight:700;text-transform:uppercase;margin-bottom:4px">
              ⚽ Sports Enrichment Applied
            </div>
            <div style="font-size:11px;color:#94a3b8">
              Original: <?= e(number_format($sig['sports_enrichment']['original'] ?? 0, 2)) ?>x → 
              Enriched: <?= e(number_format($sig['sports_enrichment']['enriched'] ?? 0, 2)) ?>x
              (<?= e(number_format($sig['sports_enrichment']['adjustment'] ?? 0, 2)) ?>x)
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Integration Status -->
    <div class="demo-panel" style="margin-bottom:20px">
      <h3>🔗 Integration Status</h3>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <?php $cf = $demo['cloudflare'] ?? []; ?>
        <span class="demo-badge <?= !empty($cf['available']) ? 'demo-badge-success' : 'demo-badge-warning' ?>">
          <?= !empty($cf['available']) ? '✓' : '○' ?> AI Agents <?= !empty($cf['available']) ? 'Connected' : 'Standby' ?>
        </span>
        <span class="demo-badge <?= !empty($cf['llm_enhanced']) ? 'demo-badge-success' : 'demo-badge-warning' ?>">
          <?= !empty($cf['llm_enhanced']) ? '✓' : '○' ?> LLM <?= !empty($cf['llm_enhanced']) ? 'Enhanced' : 'Standby' ?>
        </span>
        <span class="demo-badge <?= !empty($demo['sports']) ? 'demo-badge-success' : 'demo-badge-warning' ?>">
          <?= !empty($demo['sports']) ? '✓' : '○' ?> Sports Intel <?= !empty($demo['sports']) ? 'Enriching' : 'Awaiting' ?>
        </span>
        <span class="demo-badge demo-badge-success">
          ✓ Agent Bus Registered (9 agents)
        </span>
        <span class="demo-badge demo-badge-success">
          ✓ MCP Tools 6 Active
        </span>
        <span class="demo-badge demo-badge-info">
          🎮 Crash feed: <?= e(strtoupper($demo['aviator']['health']['mode'] ?? 'live')) ?>
        </span>
      </div>
    </div>

    <!-- Aviator Stats + Sports Signals -->
    <div class="demo-grid" style="grid-template-columns:1fr 1fr">
      <!-- Aviator Statistics -->
      <div class="demo-panel">
        <h3>📊 Live Crash Statistics</h3>
        <?php $stats = $demo['aviator']['stats'] ?? []; ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div class="demo-stat">
            <div class="demo-stat-label">Total Rounds</div>
            <div class="demo-stat-value"><?= e(number_format($stats['total_rounds'] ?? 0)) ?></div>
          </div>
          <div class="demo-stat">
            <div class="demo-stat-label">Mean</div>
            <div class="demo-stat-value"><?= e(number_format($stats['mean'] ?? 0, 2)) ?>x</div>
          </div>
          <div class="demo-stat">
            <div class="demo-stat-label">Median</div>
            <div class="demo-stat-value"><?= e(number_format($stats['median'] ?? 0, 2)) ?>x</div>
          </div>
          <div class="demo-stat">
            <div class="demo-stat-label">Std Dev</div>
            <div class="demo-stat-value"><?= e(number_format($stats['stddev'] ?? 0, 2)) ?></div>
          </div>
          <div class="demo-stat">
            <div class="demo-stat-label">Min</div>
            <div class="demo-stat-value"><?= e(number_format($stats['min'] ?? 0, 2)) ?>x</div>
          </div>
          <div class="demo-stat">
            <div class="demo-stat-label">Max</div>
            <div class="demo-stat-value"><?= e(number_format($stats['max'] ?? 0, 2)) ?>x</div>
          </div>
        </div>
        <div style="margin-top:12px;padding:10px;background:rgba(139,92,246,.1);border:1px solid #8b5cf644;border-radius:8px">
          <div style="font-size:10px;color:#8b5cf6;font-weight:700">Distribution: <?= e(strtoupper($stats['distribution'] ?? 'geometric')) ?></div>
          <div style="font-size:10px;color:#94a3b8;margin-top:2px">House Edge: <?= e(number_format(($stats['house_edge'] ?? 0.03) * 100, 1)) ?>%</div>
        </div>
      </div>

      <!-- Sports Intelligence Signals -->
      <div class="demo-panel">
        <h3>⚽ Sports Intelligence Signals</h3>
        <?php $sports = $demo['sports'] ?? []; ?>
        <?php if (!empty($sports)): ?>
          <div style="display:flex;flex-direction:column;gap:8px">
            <div class="demo-stat">
              <div class="demo-stat-label">Market Sentiment</div>
              <div class="demo-stat-value" style="color:<?= $sports['market_sentiment'] === 'bullish' ? '#22c55e' : ($sports['market_sentiment'] === 'bearish' ? '#ef4444' : '#f59e0b') ?>">
                <?= e(strtoupper($sports['market_sentiment'] ?? 'neutral')) ?>
              </div>
              <div style="font-size:10px;color:#64748b;margin-top:2px">Score: <?= e(number_format($sports['sentiment_score'] ?? 0.5, 2)) ?></div>
            </div>
            <div class="demo-stat">
              <div class="demo-stat-label">Event Activity</div>
              <div class="demo-stat-value"><?= e(strtoupper($sports['event_activity'] ?? 'normal')) ?></div>
            </div>
            <div class="demo-stat">
              <div class="demo-stat-label">Major Event</div>
              <div class="demo-stat-value"><?= !empty($sports['major_event']) ? '✓ YES' : '✗ NO' ?></div>
            </div>
            <div class="demo-stat">
              <div class="demo-stat-label">Volatility</div>
              <div class="demo-stat-value"><?= e(strtoupper($sports['volatility_signal'] ?? 'normal')) ?></div>
            </div>
          </div>
        <?php else: ?>
          <div style="text-align:center;padding:20px;color:#64748b">
            <div style="font-size:40px;margin-bottom:8px">⚽</div>
            <div>Sports Intelligence</div>
            <div style="font-size:11px;margin-top:4px">Connect sports data to activate</div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 9 Specialist Agents -->
    <div class="demo-panel" style="margin-bottom:20px">
      <h3>🤖 9 Specialist AI Agents</h3>
      <div class="demo-agents">
        <?php foreach ($demo['signal']['agents'] ?? [] as $agent): ?>
          <div class="demo-agent">
            <div style="display:flex;justify-content:space-between;align-items:center">
              <div class="demo-agent-name"><?= e($agent['name']) ?></div>
              <div class="demo-agent-conf"><?= e(number_format($agent['confidence'] * 100, 0)) ?>% confidence</div>
            </div>
            <div class="demo-agent-estimate"><?= e(number_format($agent['estimate'], 2)) ?>x</div>
            <div style="font-size:10px;color:#64748b"><?= e($agent['reasoning']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Data Flow Visualization -->
    <div class="demo-panel">
      <h3>🔄 Data Flow Visualization</h3>
      <div class="demo-flow">
        <div class="demo-flow-step">
          <div class="demo-flow-icon">🎮</div>
          <div class="demo-flow-text">
            <div class="demo-flow-title">1. Live crash provider</div>
            <div class="demo-flow-desc">Fetches real multiplier history (<?= e(count($demo['aviator']['history'] ?? [])) ?> rounds)</div>
          </div>
        </div>
        <div class="demo-flow-step">
          <div class="demo-flow-icon">🤖</div>
          <div class="demo-flow-text">
            <div class="demo-flow-title">2. 9 Specialist Agents</div>
            <div class="demo-flow-desc">Each agent analyzes data using different methodologies (statistical, pattern, probability)</div>
          </div>
        </div>
        <?php if (!empty($demo['sports'])): ?>
        <div class="demo-flow-step">
          <div class="demo-flow-icon">⚽</div>
          <div class="demo-flow-text">
            <div class="demo-flow-title">3. Sports Intelligence Enrichment</div>
            <div class="demo-flow-desc">Extracts market sentiment from connected sports data (±15% adjustment)</div>
          </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($demo['cloudflare']['llm_enhanced'])): ?>
        <div class="demo-flow-step">
          <div class="demo-flow-icon">⚡</div>
          <div class="demo-flow-text">
            <div class="demo-flow-title">4. LLM Enhancement</div>
            <div class="demo-flow-desc">AI reasoning via the LLM router (70% statistical / 30% LLM blend)</div>
          </div>
        </div>
        <?php endif; ?>
        <div class="demo-flow-step">
          <div class="demo-flow-icon">🎯</div>
          <div class="demo-flow-text">
            <div class="demo-flow-title"><?= !empty($demo['sports']) ? (strpos(json_encode($demo['cloudflare'] ?? []), 'true') !== false ? '5' : '4') : '3' ?>. Final Prediction</div>
            <div class="demo-flow-desc">Combined output: <?= e(number_format($demo['signal']['predicted'] ?? 2.0, 2)) ?>x with <?= e(number_format($demo['signal']['confidence'] ?? 50, 0)) ?>% confidence</div>
          </div>
        </div>
      </div>
    </div>

    <?php endif; ?>

    <!-- Disclaimer -->
    <div style="margin-top:24px;padding:16px;background:rgba(245,158,11,.1);border:1px solid #f59e0b44;border-radius:12px;color:#f59e0b;font-size:11px">
      <strong>⚠️ EDUCATIONAL ANALYSIS</strong> — Rounds come from a live crash feed. Crash games use provably fair RNG. No prediction system can guarantee outcomes.
    </div>
  </div>
</div>

<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $dashboard @var array $signal @var array $accuracy @var array $history */
$ic = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
$provider = $dashboard['provider'] ?? [];
$agents = $dashboard['agents'] ?? [];
$acc = $accuracy ?? [];
?>
<style>
/* ═══ Multiplier Intelligence Theme ════════════════════════════════ */
.mi-bg{background:linear-gradient(180deg,#0a0e27 0%,#131836 50%,#0a0e27 100%);min-height:100vh;padding:20px}
.mi-container{max-width:1400px;margin:0 auto}

/* ═══ Header ═══════════════════════════════════════════════════════ */
.mi-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;padding:16px 24px;background:linear-gradient(90deg,#1e2348 0%,#2d1b69 100%);border-radius:16px;border:1px solid #3d4785}
.mi-header h1{font-size:22px;font-weight:800;margin:0;color:#fff;display:flex;align-items:center;gap:10px}
.mi-header .logo{font-size:28px}
.mi-badge{background:linear-gradient(90deg,#22c55e,#16a34a);color:#fff;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}

/* ═══ Disclaimer ═══════════════════════════════════════════════════ */
.mi-disclaimer{background:#f59e0b15;border:1px solid #f59e0b44;border-radius:12px;padding:10px 16px;margin-bottom:20px;color:#f59e0b;font-size:11px;display:flex;gap:8px;align-items:flex-start}

/* ═══ Command Center Grid ══════════════════════════════════════════ */
.mi-grid{display:grid;grid-template-columns:1fr 1fr 320px;gap:16px;margin-bottom:16px}
.mi-panel{background:rgba(20,25,60,.6);backdrop-filter:blur(10px);border:1px solid #2d3568;border-radius:16px;padding:20px;position:relative;overflow:hidden}
.mi-panel::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,#6366f1,transparent)}
.mi-panel-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin:0 0 12px;display:flex;align-items:center;gap:6px}
.mi-panel-title .dot{width:6px;height:6px;border-radius:50%;background:#22c55e;box-shadow:0 0 8px #22c55e}

/* ═══ Main Signal Display ══════════════════════════════════════════ */
.mi-signal-main{text-align:center;padding:30px 20px;background:linear-gradient(135deg,#1e1b4b 0%,#312e81 50%,#4c1d95 100%);border-radius:16px;border:1px solid #6366f1;margin-bottom:16px;position:relative;overflow:hidden}
.mi-signal-main::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 50% 0%,rgba(99,102,241,.3) 0%,transparent 50%)}
.mi-signal-status{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.15em;color:#a5b4fc;margin-bottom:12px;position:relative}
.mi-signal-value{font-size:84px;font-weight:900;background:linear-gradient(135deg,#fff 0%,#a5b4fc 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin:8px 0;position:relative;line-height:1}
.mi-signal-label{font-size:12px;color:#c7d2fe;margin-bottom:16px;position:relative}
.mi-signal-range{display:flex;justify-content:center;gap:24px;margin-bottom:16px;position:relative}
.mi-range-item{text-align:center}
.mi-range-label{font-size:10px;color:#94a3b8;text-transform:uppercase}
.mi-range-value{font-size:20px;font-weight:700;color:#e0e7ff}

/* ═══ Confidence + Risk ════════════════════════════════════════════ */
.mi-metrics{display:grid;grid-template-columns:1fr 1fr;gap:12px;position:relative}
.mi-metric{text-align:center;padding:12px;background:rgba(15,23,42,.5);border-radius:12px;border:1px solid #1e293b}
.mi-metric-label{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px}
.mi-metric-value{font-size:24px;font-weight:800}
.mi-confidence{color:#22c55e}
.mi-risk-low{color:#22c55e}.mi-risk-medium{color:#f59e0b}.mi-risk-high{color:#ef4444}.mi-risk-extreme{color:#dc2626}

/* ═══ Get Signal Button ════════════════════════════════════════════ */
.mi-btn-signal{width:100%;padding:16px;background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%);color:#fff;border:none;border-radius:12px;font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;cursor:pointer;transition:all .2s;position:relative;overflow:hidden;margin-top:16px}
.mi-btn-signal:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(99,102,241,.4)}
.mi-btn-signal:disabled{opacity:.5;cursor:not-allowed;transform:none}
.mi-btn-signal.analyzing{background:linear-gradient(135deg,#3b82f6 0%,#6366f1 100%)}

/* ═══ Live Multiplier ══════════════════════════════════════════════ */
.mi-live{text-align:center;padding:24px 16px}
.mi-live-value{font-size:48px;font-weight:900;color:#fff;margin:8px 0;font-variant-numeric:tabular-nums}
.mi-live-value.rising{color:#22c55e}
.mi-live-value.crashed{color:#ef4444}
.mi-live-status{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.1em}
.mi-live-status.active{color:#22c55e}
.mi-live-status.waiting{color:#f59e0b}
.mi-live-status.crashed{color:#ef4444}

/* ═══ Stats Grid ══════════════════════════════════════════════════ */
.mi-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
.mi-stat{background:rgba(15,23,42,.5);border-radius:10px;padding:12px;border:1px solid #1e293b}
.mi-stat-label{font-size:10px;color:#64748b;text-transform:uppercase;margin-bottom:4px}
.mi-stat-value{font-size:18px;font-weight:700;color:#e2e8f0}

/* ═══ Agents List ══════════════════════════════════════════════════ */
.mi-agents{display:flex;flex-direction:column;gap:8px;max-height:400px;overflow-y:auto}
.mi-agent{background:rgba(15,23,42,.5);border-radius:10px;padding:12px;border:1px solid #1e293b}
.mi-agent-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
.mi-agent-name{font-size:12px;font-weight:600;color:#e2e8f0}
.mi-agent-conf{font-size:11px;font-weight:700}
.mi-agent-output{font-size:20px;font-weight:800;color:#a5b4fc;margin:4px 0}
.mi-agent-reasoning{font-size:10px;color:#64748b;line-height:1.4}

/* ═══ History Table ════════════════════════════════════════════════ */
.mi-history{display:flex;flex-direction:column;gap:4px;max-height:320px;overflow-y:auto}
.mi-round{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:rgba(15,23,42,.5);border-radius:8px;font-size:12px}
.mi-round-id{color:#64748b;font-family:monospace;font-size:10px}
.mi-round-value{font-weight:700;font-size:14px;font-variant-numeric:tabular-nums}
.mi-round-value.low{color:#ef4444}
.mi-round-value.medium{color:#f59e0b}
.mi-round-value.high{color:#22c55e}
.mi-round-value.epic{color:#a855f7}

/* ═══ Accuracy Panel ══════════════════════════════════════════════ */
.mi-accuracy-card{background:linear-gradient(135deg,#065f46 0%,#064e3b 100%);border-radius:12px;padding:16px;border:1px solid #10b981;margin-bottom:12px}
.mi-accuracy-value{font-size:40px;font-weight:900;color:#fff;line-height:1}
.mi-accuracy-label{font-size:10px;color:#6ee7b7;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px}

/* ═══ Agent Breakdown ══════════════════════════════════════════════ */
.mi-breakdown{display:flex;flex-direction:column;gap:6px}
.mi-breakdown-item{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:rgba(15,23,42,.5);border-radius:8px}
.mi-breakdown-name{font-size:11px;color:#cbd5e1;font-weight:500}
.mi-breakdown-value{font-size:12px;font-weight:700;color:#a5b4fc}

/* ═══ Model Info ══════════════════════════════════════════════════ */
.mi-model{background:rgba(99,102,241,.1);border:1px solid #6366f1;border-radius:10px;padding:12px;margin-top:12px}
.mi-model-code{font-family:monospace;font-size:11px;color:#a5b4fc;font-weight:700}
.mi-model-name{font-size:12px;color:#e2e8f0;margin-top:2px}

/* ═══ Disclaimer Footer ════════════════════════════════════════════ */
.mi-footer{margin-top:24px;padding:16px;background:rgba(15,23,42,.5);border-radius:12px;border:1px solid #1e293b}
.mi-footer p{margin:4px 0;font-size:11px;color:#64748b;line-height:1.5}
.mi-footer b{color:#94a3b8}

/* ═══ Animations ══════════════════════════════════════════════════ */
@keyframes pulse-ring{0%{transform:scale(1);opacity:1}100%{transform:scale(1.3);opacity:0}}
@keyframes scan{0%{transform:translateX(-100%)}100%{transform:translateX(100%)}}
.mi-scanning{position:relative;overflow:hidden}
.mi-scanning::after{content:'';position:absolute;top:0;bottom:0;width:30%;background:linear-gradient(90deg,transparent,rgba(99,102,241,.2),transparent);animation:scan 1.5s ease-in-out infinite}
.mi-pulse-dot{width:10px;height:10px;border-radius:50%;background:#22c55e;position:relative;display:inline-block}
.mi-pulse-dot::before{content:'';position:absolute;inset:0;border-radius:50%;background:#22c55e;animation:pulse-ring 1.5s ease-out infinite}

/* ═══ Integration Status ═════════════════════════════════════════ */
.mi-integration{display:flex;gap:8px;flex-wrap:wrap;padding:10px 14px;background:rgba(15,23,42,.5);border-radius:10px;border:1px solid #1e293b;margin-bottom:16px}
.mi-int-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.mi-int-pill.active{background:#22c55e22;color:#22c55e;border:1px solid #22c55e44}
.mi-int-pill.inactive{background:#64748b22;color:#94a3b8;border:1px solid #64748b44}
.mi-int-pill .dot{width:6px;height:6px;border-radius:50%;background:currentColor;box-shadow:0 0 6px currentColor}

@media(max-width:1200px){.mi-grid{grid-template-columns:1fr 1fr}}
@media(max-width:768px){.mi-grid{grid-template-columns:1fr}.mi-signal-value{font-size:60px}}
</style>

<div class="mi-bg">
  <div class="mi-container">
    <!-- Header -->
    <div class="mi-header">
      <h1><span class="logo">🚀</span> AI MULTIPLIER INTELLIGENCE <span class="mi-badge">WINDELS AI OS</span></h1>
      <div style="display:flex;gap:12px;align-items:center">
        <span style="color:#94a3b8;font-size:11px">Provider: <b style="color:#e2e8f0"><?= e($provider['name'] ?? 'Live') ?></b></span>
        <?php $liveMode = ($provider['metadata']['mode'] ?? '') === 'live'; ?>
        <span class="mi-badge" style="<?= $liveMode ? '' : 'background:linear-gradient(90deg,#f59e0b,#d97706)' ?>"><?= $liveMode ? 'LIVE DATA' : 'FEED OFFLINE' ?></span>
        <span class="mi-pulse-dot"></span>
      </div>
    </div>
    
    <!-- Disclaimer -->
    <div class="mi-disclaimer">
      <span style="font-size:14px">⚠️</span>
      <div>
        <b>LIVE ANALYSIS</b> — Rounds are pulled from a real crash provider (not simulated). Crash games are inherently random.
        No system can guarantee predictions. Past performance does not guarantee future results. Never risk more than you can afford to lose.
      </div>
    </div>

    <?php $intg = $integration ?? []; ?>
    <div class="mi-integration" title="Integration status with Windels AI Agents and Sports Intelligence">
      <?php
        $cfActive = !empty($intg['cloudflare']['available']);
        $llmActive = !empty($intg['llm']['available']);
        $sportsActive = !empty($intg['sports']['available']);
        $regActive = !empty($intg['registered']);
      ?>
      <span class="mi-int-pill <?= $cfActive ? 'active' : 'inactive' ?>" title="Windels AI Agents integration">
        <span class="dot"></span>
        AI Agents <?= $cfActive ? 'Connected' : 'Standby' ?>
      </span>
      <span class="mi-int-pill <?= $llmActive ? 'active' : 'inactive' ?>" title="LLM model enhancement">
        <span class="dot"></span>
        LLM Enhancement <?= $llmActive ? 'Active (' . (int)($intg['llm']['providers'] ?? 0) . ' providers)' : 'Standby' ?>
      </span>
      <span class="mi-int-pill <?= $sportsActive ? 'active' : 'inactive' ?>" title="Sports Intelligence enrichment">
        <span class="dot"></span>
        Sports Intel <?= $sportsActive ? 'Enriching' : 'Awaiting Config' ?>
      </span>
      <span class="mi-int-pill <?= $regActive ? 'active' : 'inactive' ?>" title="9 specialist agents registered with AgentCommunicationBus">
        <span class="dot"></span>
        Agent Bus <?= $regActive ? 'Registered (9 agents)' : 'Unregistered' ?>
      </span>
      <span class="mi-int-pill <?= $cfActive ? 'active' : 'inactive' ?>" title="6 multiplier.* MCP tools available to all agents">
        <span class="dot"></span>
        MCP Tools <?= $cfActive ? '6 Active' : 'Standby' ?>
      </span>
      <?php if ($sportsActive && !empty($intg['sports']['signals'])): ?>
        <span class="mi-int-pill active" title="Current market sentiment from sports betting odds">
          📊 Sentiment: <?= e(strtoupper($intg['sports']['signals']['sentiment'] ?? 'neutral')) ?>
        </span>
      <?php endif; ?>
    </div>
    
    <!-- Main Signal Display -->
    <div class="mi-signal-main" id="signal-main">
      <div class="mi-signal-status" id="signal-status">
        <span class="mi-pulse-dot"></span> <?= e($signal['status'] ?? 'ACTIVE') ?>
      </div>
      <div class="mi-signal-value" id="signal-value"><?= ($signal['status'] ?? '') === 'NO_DATA' ? '—' : e(number_format($signal['predictedMultiplier'] ?? 0, 2)) . 'x' ?></div>
      <div class="mi-signal-label">NEXT SIGNAL ESTIMATE</div>
      <div class="mi-signal-range">
        <div class="mi-range-item">
          <div class="mi-range-label">Min Range</div>
          <div class="mi-range-value" id="signal-min"><?= e(number_format($signal['predictedMin'] ?? 0, 2)) ?>x</div>
        </div>
        <div class="mi-range-item">
          <div class="mi-range-label">Max Range</div>
          <div class="mi-range-value" id="signal-max"><?= e(number_format($signal['predictedMax'] ?? 0, 2)) ?>x</div>
        </div>
      </div>
      <div class="mi-metrics">
        <div class="mi-metric">
          <div class="mi-metric-label">Confidence</div>
          <div class="mi-metric-value mi-confidence" id="signal-conf"><?= e(number_format(($signal['confidence'] ?? 0) * 100, 0)) ?>%</div>
        </div>
        <div class="mi-metric">
          <div class="mi-metric-label">Risk Level</div>
          <div class="mi-metric-value mi-risk-<?= strtolower($signal['risk'] ?? 'MEDIUM') ?>" id="signal-risk"><?= e($signal['risk'] ?? 'MEDIUM') ?></div>
        </div>
      </div>
      <button class="mi-btn-signal" id="btn-signal" onclick="generateSignal()">
        ⚡ GET NEXT SIGNAL
      </button>
    </div>
    
    <!-- Command Center Grid -->
    <div class="mi-grid">
      <!-- Left: Live Monitor + Stats -->
      <div>
        <div class="mi-panel" style="margin-bottom:16px">
          <div class="mi-panel-title"><span class="dot"></span> LIVE MULTIPLIER MONITOR</div>
          <div class="mi-live">
            <div class="mi-live-status <?= $provider['isInRound'] ? 'active' : 'waiting' ?>" id="live-status">
              <?= $provider['isInRound'] ? '● ROUND IN PROGRESS' : '○ WAITING FOR ROUND' ?>
            </div>
            <div class="mi-live-value" id="live-value"><?= e(number_format($provider['currentMultiplier'] ?? 1.0, 2)) ?>x</div>
            <div style="font-size:11px;color:#64748b">Real-time multiplier</div>
          </div>
        </div>
        
        <div class="mi-panel">
          <div class="mi-panel-title"><span class="dot"></span> HISTORICAL ROUNDS</div>
          <div class="mi-history">
            <?php foreach (array_reverse($history) as $round): 
              $m = (float)($round['multiplier'] ?? 0);
              $cls = $m < 2 ? 'low' : ($m < 5 ? 'medium' : ($m < 10 ? 'high' : 'epic'));
            ?>
              <div class="mi-round">
                <span class="mi-round-id">#<?= e($round['roundId'] ?? '') ?></span>
                <span class="mi-round-value <?= $cls ?>"><?= e(number_format($m, 2)) ?>x</span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      
      <!-- Center: Agent Outputs -->
      <div>
        <div class="mi-panel">
          <div class="mi-panel-title"><span class="dot"></span> AGENT ANALYSIS (<?= count($signal['agents'] ?? []) ?> agents)</div>
          <div class="mi-agents" id="agents-list">
            <?php foreach ($signal['agents'] ?? [] as $agent): ?>
              <div class="mi-agent">
                <div class="mi-agent-head">
                  <span class="mi-agent-name"><?= e($agent['agent_name'] ?? '') ?></span>
                  <span class="mi-agent-conf" style="color:<?= ($agent['confidence'] ?? 0) > 0.6 ? '#22c55e' : '#f59e0b' ?>">
                    <?= e(number_format(($agent['confidence'] ?? 0) * 100, 0)) ?>%
                  </span>
                </div>
                <div class="mi-agent-output"><?= e(number_format($agent['estimate'] ?? 0, 2)) ?>x</div>
                <div class="mi-agent-reasoning"><?= e($agent['reasoning'] ?? '') ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      
      <!-- Right: Accuracy + Stats -->
      <div>
        <div class="mi-panel" style="margin-bottom:16px">
          <div class="mi-panel-title"><span class="dot"></span> MODEL PERFORMANCE</div>
          <?php if (!empty($acc['available'])): ?>
            <div class="mi-accuracy-card">
              <div class="mi-accuracy-label">Last <?= (int)($acc['window'] ?? 100) ?> Predictions</div>
              <div class="mi-accuracy-value"><?= e(number_format($acc['accuracy20'] ?? 0, 0)) ?>%</div>
              <div style="font-size:10px;color:#6ee7b7;margin-top:4px">Within 20% accuracy</div>
            </div>
            <div class="mi-stats">
              <div class="mi-stat">
                <div class="mi-stat-label">Validated</div>
                <div class="mi-stat-value"><?= (int)($acc['total'] ?? 0) ?></div>
              </div>
              <div class="mi-stat">
                <div class="mi-stat-label">Avg Error</div>
                <div class="mi-stat-value"><?= e(number_format($acc['avgError'] ?? 0, 1)) ?>%</div>
              </div>
            </div>
          <?php else: ?>
            <div style="text-align:center;padding:20px">
              <div style="font-size:32px;margin-bottom:8px">📊</div>
              <div style="font-size:12px;color:#64748b">No validated predictions yet.<br>Accuracy will appear after validation.</div>
            </div>
          <?php endif; ?>
        </div>
        
        <div class="mi-panel">
          <div class="mi-panel-title"><span class="dot"></span> COMMAND CENTER</div>
          <div class="mi-breakdown">
            <div class="mi-breakdown-item">
              <span class="mi-breakdown-name">Active Agents</span>
              <span class="mi-breakdown-value"><?= count($agents) ?></span>
            </div>
            <div class="mi-breakdown-item">
              <span class="mi-breakdown-name">Data Points</span>
              <span class="mi-breakdown-value"><?= (int)($signal['features']['count'] ?? 0) ?></span>
            </div>
            <div class="mi-breakdown-item">
              <span class="mi-breakdown-name">Latency</span>
              <span class="mi-breakdown-value"><?= (int)($signal['latencyMs'] ?? 0) ?>ms</span>
            </div>
            <div class="mi-breakdown-item">
              <span class="mi-breakdown-name">Agent Agreement</span>
              <span class="mi-breakdown-value"><?= e(number_format(($signal['agreement'] ?? 0) * 100, 0)) ?>%</span>
            </div>
          </div>
          <div class="mi-model">
            <div class="mi-model-code"><?= e($signal['modelCode'] ?? '') ?></div>
            <div class="mi-model-name"><?= e($signal['modelName'] ?? '') ?></div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Footer Disclaimer -->
    <div class="mi-footer">
      <p><b>📊 HOW IT WORKS:</b> <?= count($agents) ?> specialist AI agents analyze historical patterns, statistical distributions, sequences, anomalies, and risk to produce an ensemble prediction with confidence scoring.</p>
      <p><b>⚠️ IMPORTANT:</b> Crash games use provably-fair random algorithms. <b>No AI system can predict random outcomes with certainty.</b> This platform demonstrates statistical analysis methodology, not guaranteed prediction.</p>
      <p><b>🎯 ACCURACY:</b> We transparently track all predictions against actual outcomes. The accuracy statistics above reflect real performance — never fabricated.</p>
      <p><b>🔒 PROVIDER:</b> Currently using <b><?= e($provider['name'] ?? 'Live') ?></b> — <?= e($provider['metadata']['disclaimer'] ?? 'Live crash history') ?></p>
    </div>
  </div>
</div>

<script>
(function(){
  'use strict';
  
  // Auto-refresh live multiplier
  function updateLive() {
    fetch('/multiplier/live')
      .then(function(r){return r.json()})
      .then(function(d){
        if (d.ok) {
          var el = document.getElementById('live-value');
          var statusEl = document.getElementById('live-status');
          if (d.currentMultiplier !== null) {
            el.textContent = d.currentMultiplier.toFixed(2) + 'x';
            el.className = 'mi-live-value rising';
            statusEl.textContent = '● ROUND IN PROGRESS';
            statusEl.className = 'mi-live-status active';
          } else {
            el.textContent = '1.00x';
            el.className = 'mi-live-value';
            statusEl.textContent = '○ WAITING FOR ROUND';
            statusEl.className = 'mi-live-status waiting';
          }
        }
      })
      .catch(function(){});
  }
  
  setInterval(updateLive, 1000);
  
  // Generate new signal
  window.generateSignal = function() {
    var btn = document.getElementById('btn-signal');
    var main = document.getElementById('signal-main');
    
    btn.disabled = true;
    btn.textContent = '⏳ ANALYZING...';
    btn.classList.add('analyzing');
    main.classList.add('mi-scanning');
    
    document.getElementById('signal-status').innerHTML = '<span class="mi-pulse-dot"></span> ANALYZING...';
    
    fetch('/multiplier/generate_signal', {method: 'POST'})
      .then(function(r){return r.json()})
      .then(function(d){
        if (d.ok && d.signal) {
          var s = d.signal;
          document.getElementById('signal-value').textContent = s.status === 'NO_DATA' || s.predictedMultiplier == null ? '—' : (Number(s.predictedMultiplier).toFixed(2) + 'x');
          document.getElementById('signal-min').textContent = (s.predictedMin || 0).toFixed(2) + 'x';
          document.getElementById('signal-max').textContent = (s.predictedMax || 0).toFixed(2) + 'x';
          document.getElementById('signal-conf').textContent = Math.round(s.confidence * 100) + '%';
          
          var riskEl = document.getElementById('signal-risk');
          riskEl.textContent = s.risk;
          riskEl.className = 'mi-metric-value mi-risk-' + s.risk.toLowerCase();
          
          document.getElementById('signal-status').innerHTML = '<span class="mi-pulse-dot"></span> ' + s.status;
          
          // Update agents
          var agentsHtml = '';
          (s.agents || []).forEach(function(a){
            var confColor = (a.confidence || 0) > 0.6 ? '#22c55e' : '#f59e0b';
            agentsHtml += '<div class="mi-agent">' +
              '<div class="mi-agent-head">' +
                '<span class="mi-agent-name">' + esc(a.agent_name || '') + '</span>' +
                '<span class="mi-agent-conf" style="color:' + confColor + '">' + Math.round((a.confidence || 0) * 100) + '%</span>' +
              '</div>' +
              '<div class="mi-agent-output">' + (a.estimate || 0).toFixed(2) + 'x</div>' +
              '<div class="mi-agent-reasoning">' + esc(a.reasoning || '') + '</div>' +
            '</div>';
          });
          document.getElementById('agents-list').innerHTML = agentsHtml;
        }
      })
      .catch(function(e){
        alert('Error: ' + e.message);
      })
      .finally(function(){
        btn.disabled = false;
        btn.textContent = '⚡ GET NEXT SIGNAL';
        btn.classList.remove('analyzing');
        main.classList.remove('mi-scanning');
      });
  };
  
  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }
})();
</script>

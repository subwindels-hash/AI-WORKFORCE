<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $dashboard @var array $platformStatus @var array $agents @var array $tools */
$ic = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
$overview = $dashboard['overview'] ?? [];
$health = $dashboard['health'] ?? [];
$agentsStatus = $dashboard['agents'] ?? [];
$models = $dashboard['models'] ?? [];
$workflows = $dashboard['workflows'] ?? [];
$sessions = $dashboard['sessions'] ?? [];
$activity = $dashboard['recentActivity'] ?? [];
?>
<style>
/* ═══ Observability Dashboard ══════════════════════════════════════ */
.obs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:20px}
.obs-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:16px}
.obs-card .lbl{font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
.obs-card .val{font-size:22px;font-weight:700;color:#fff}
.obs-card .sub{font-size:11px;color:var(--dim);margin-top:2px}
.obs-hero{background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%);border-radius:var(--radius);padding:24px;margin-bottom:20px;color:#fff;position:relative;overflow:hidden}
.obs-hero::after{content:'';position:absolute;top:-50%;right:-20%;width:60%;height:200%;background:radial-gradient(circle,rgba(99,102,241,.12) 0%,transparent 70%)}
.obs-hero h2{font-size:22px;font-weight:800;margin:0 0 6px;position:relative;z-index:1}
.obs-hero p{font-size:13px;opacity:.8;margin:0;position:relative;z-index:1}
.health-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600}
.health-pill.healthy{background:#22c55e22;color:#22c55e}
.health-pill.degraded{background:#f5a62322;color:#f5a623}
.health-pill.down{background:#fb5d6b22;color:#fb5d6b}
.agent-row{display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:1px solid var(--line)}
.agent-row:last-child{border-bottom:none}
.agent-row .name{font-weight:600;font-size:13px;min-width:120px}
.agent-row .stats{display:flex;gap:12px;font-size:12px;color:var(--dim)}
.agent-row .stats b{color:var(--text)}
.tool-cat{background:var(--panel2);border-radius:var(--radius-sm);padding:10px 12px;margin-bottom:6px}
.tool-cat .cat-name{font-size:12px;font-weight:600;color:var(--brand)}
.tool-cat .cat-count{font-size:11px;color:var(--dim)}
.activity-item{padding:8px 0;border-bottom:1px solid var(--line);font-size:12px}
.activity-item:last-child{border-bottom:none}
.activity-item .time{color:var(--dim);font-size:11px}
.activity-item .type{font-weight:600;color:var(--brand)}

@media(max-width:768px){.obs-grid{grid-template-columns:1fr 1fr}}
</style>

<div class="page-head">
  <div>
    <h2>🔭 Agent Platform — Observability</h2>
    <p>Monitor agents, models, workflows, tools, and system health across the entire AI infrastructure.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn" href="/app/workforce">Agent Console</a>
    <a class="btn" href="/admin/api">AI Providers</a>
  </div>
</div>

<!-- Hero with System Health -->
<div class="obs-hero">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;position:relative;z-index:1">
    <div>
      <h2>Windels AI Agents</h2>
      <p>Edge AI inference · Multi-provider routing · Durable sessions · Tool orchestration · Workflow engine</p>
    </div>
    <span class="health-pill <?= ($health['overall'] ?? 'HEALTHY') === 'HEALTHY' ? 'healthy' : (($health['overall'] ?? '') === 'DEGRADED' ? 'degraded' : 'down') ?>">
      ● <?= e($health['overall'] ?? 'HEALTHY') ?>
    </span>
  </div>
</div>

<!-- Overview Stats -->
<div class="obs-grid">
  <div class="obs-card">
    <div class="lbl">Today's Executions</div>
    <div class="val"><?= (int)($overview['todayExecutions'] ?? 0) ?></div>
    <div class="sub">This week: <?= (int)($overview['weekExecutions'] ?? 0) ?></div>
  </div>
  <div class="obs-card">
    <div class="lbl">Error Rate</div>
    <div class="val" style="color:<?= ($overview['errorRate'] ?? 0) > 5 ? 'var(--red)' : 'var(--green)' ?>"><?= e(number_format($overview['errorRate'] ?? 0, 1)) ?>%</div>
    <div class="sub"><?= (int)($overview['todayErrors'] ?? 0) ?> errors today</div>
  </div>
  <div class="obs-card">
    <div class="lbl">Active Sessions</div>
    <div class="val"><?= (int)($overview['activeSessions'] ?? 0) ?></div>
    <div class="sub">Agent conversations</div>
  </div>
  <div class="obs-card">
    <div class="lbl">Model Calls</div>
    <div class="val"><?= (int)(($overview['modelUsage'] ?? [])['totalCalls'] ?? 0) ?></div>
    <div class="sub"><?= number_format((($overview['modelUsage'] ?? [])['totalTokens'] ?? 0)) ?> tokens</div>
  </div>
  <div class="obs-card">
    <div class="lbl">Pending Workflows</div>
    <div class="val"><?= (int)($overview['pendingWorkflows'] ?? 0) ?></div>
    <div class="sub">Scheduled tasks</div>
  </div>
  <div class="obs-card">
    <div class="lbl">AI Cost (est.)</div>
    <div class="val">$<?= e(number_format(($overview['modelUsage'] ?? [])['totalCostUsd'] ?? 0, 4)) ?></div>
    <div class="sub">Total estimated</div>
  </div>
</div>

<div class="grid cols-main">
  <div>
    <!-- Agent Status -->
    <div class="panel" style="margin-bottom:16px">
      <h3>Agent Status</h3>
      <div class="body" style="padding:0">
        <?php if (empty($agentsStatus)): ?>
          <p class="dim" style="padding:14px">No agent activity recorded yet.</p>
        <?php else: ?>
          <?php foreach ($agentsStatus as $name => $a): ?>
          <div class="agent-row">
            <span class="name"><?= e(ucfirst(str_replace('_', ' ', $name))) ?></span>
            <div class="stats">
              <span>✓ <b><?= (int)$a['completed'] ?></b></span>
              <span>✗ <b style="color:var(--red)"><?= (int)$a['failed'] ?></b></span>
              <span>Rate: <b><?= e($a['successRate'] ?? 0) ?>%</b></span>
              <span>Avg: <b><?= (int)($a['avgLatencyMs'] ?? 0) ?>ms</b></span>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Model Providers -->
    <div class="panel" style="margin-bottom:16px">
      <h3>Model Providers</h3>
      <div class="body" style="padding:0">
        <?php if (empty($models['providers'])): ?>
          <p class="dim" style="padding:14px">No model providers configured.</p>
        <?php else: ?>
          <?php foreach ($models['providers'] as $name => $p): ?>
          <div class="agent-row">
            <span class="name"><?= e(ucfirst(str_replace('_', ' ', $name))) ?></span>
            <div class="stats">
              <span class="health-pill <?= ($p['health']['status'] ?? 'UNKNOWN') === 'HEALTHY' ? 'healthy' : 'degraded' ?>" style="font-size:11px;padding:2px 8px">
                ● <?= e($p['health']['status'] ?? 'UNKNOWN') ?>
              </span>
              <span>Driver: <b><?= e($p['driver'] ?? '—') ?></b></span>
              <span>Priority: <b><?= (int)($p['priority'] ?? 0) ?></b></span>
              <?php if (!empty($p['health']['latencyMs'])): ?>
              <span>Latency: <b><?= (int)$p['health']['latencyMs'] ?>ms</b></span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent Activity -->
    <div class="panel">
      <h3>Recent Activity</h3>
      <div class="body">
        <?php if (empty($activity)): ?>
          <p class="dim">No activity yet.</p>
        <?php else: ?>
          <?php foreach (array_slice($activity, 0, 15) as $a): ?>
          <div class="activity-item">
            <span class="time"><?= e(substr($a['created_at'] ?? '', 11, 8)) ?></span>
            <span class="type"><?= e($a['type'] ?? '') ?></span>
            <span style="color:var(--dim)">— <?= e(mb_substr($a['summary'] ?? '', 0, 80)) ?></span>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div>
    <!-- Platform Components -->
    <div class="panel" style="margin-bottom:16px">
      <h3>Platform Components</h3>
      <div class="body">
        <div class="bc-row" style="display:flex;justify-content:space-between;padding:6px 0"><span>Model Router</span><span class="health-pill healthy" style="font-size:10px;padding:2px 8px">● Active</span></div>
        <div class="bc-row" style="display:flex;justify-content:space-between;padding:6px 0"><span>MCP Tool Registry</span><span class="health-pill healthy" style="font-size:10px;padding:2px 8px">● Active</span></div>
        <div class="bc-row" style="display:flex;justify-content:space-between;padding:6px 0"><span>Session Manager</span><span class="health-pill healthy" style="font-size:10px;padding:2px 8px">● Active</span></div>
        <div class="bc-row" style="display:flex;justify-content:space-between;padding:6px 0"><span>Workflow Engine</span><span class="health-pill healthy" style="font-size:10px;padding:2px 8px">● Active</span></div>
        <div class="bc-row" style="display:flex;justify-content:space-between;padding:6px 0"><span>Communication Bus</span><span class="health-pill healthy" style="font-size:10px;padding:2px 8px">● Active</span></div>
        <div class="bc-row" style="display:flex;justify-content:space-between;padding:6px 0"><span>Observability</span><span class="health-pill healthy" style="font-size:10px;padding:2px 8px">● Active</span></div>
      </div>
    </div>

    <!-- MCP Tools -->
    <div class="panel" style="margin-bottom:16px">
      <h3>MCP Tools</h3>
      <div class="body">
        <?php foreach ($tools as $code => $info): ?>
        <div class="tool-cat">
          <div style="display:flex;justify-content:space-between;align-items:center">
            <span class="cat-name"><?= e($info['label'] ?? ucfirst($code)) ?></span>
            <span class="cat-count"><?= (int)$info['count'] ?> tool(s)</span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- System Health -->
    <div class="panel" style="margin-bottom:16px">
      <h3>System Health</h3>
      <div class="body">
        <?php foreach (($health['components'] ?? []) as $comp => $status): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line)">
          <span style="font-size:12px"><?= e(ucfirst(str_replace('_', ' ', $comp))) ?></span>
          <span class="health-pill <?= $status === 'HEALTHY' || $status === 'NORMAL' ? 'healthy' : ($status === 'DEGRADED' || $status === 'HIGH' ? 'degraded' : 'down') ?>" style="font-size:10px;padding:2px 8px">
            ● <?= e($status) ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Registered Agents -->
    <div class="panel">
      <h3>Registered Agents (<?= count($agents) ?>)</h3>
      <div class="body">
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <?php foreach ($agents as $a): ?>
          <span style="background:var(--brand-soft,var(--panel2));color:var(--brand);padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600"><?= e(ucfirst(str_replace('_', ' ', $a))) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

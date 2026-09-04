<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $modules @var array $overview @var array $systemHealth @var array $recentActivity */
?>
<style>
.cc-bg{background:linear-gradient(180deg,#0a0e27 0%,#131836 50%,#0a0e27 100%);min-height:100vh;padding:20px}
.cc-container{max-width:1400px;margin:0 auto}
.cc-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;padding:20px 24px;background:linear-gradient(90deg,#1e2348 0%,#2d1b69 100%);border-radius:16px;border:1px solid #3d4785}
.cc-header h1{font-size:24px;font-weight:800;margin:0;color:#fff;display:flex;align-items:center;gap:12px}
.cc-header .logo{font-size:32px}
.cc-health{display:flex;align-items:center;gap:16px;padding:16px 24px;background:linear-gradient(135deg,#065f46 0%,#064e3b 100%);border-radius:16px;border:1px solid #10b981;margin-bottom:24px}
.cc-health-score{font-size:48px;font-weight:900;color:#fff}
.cc-health-label{font-size:11px;color:#6ee7b7;text-transform:uppercase;letter-spacing:.1em}
.cc-health-status{font-size:14px;font-weight:700;color:#a7f3d0;margin-top:4px}
.cc-health-bar{flex:1;height:8px;background:#064e3b;border-radius:4px;overflow:hidden;margin-left:20px}
.cc-health-fill{height:100%;background:linear-gradient(90deg,#10b981,#22c55e);transition:width .3s}
.cc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:24px}
.cc-module{background:rgba(20,25,60,.6);backdrop-filter:blur(10px);border:1px solid #2d3568;border-radius:16px;padding:20px;position:relative;overflow:hidden;transition:all .2s}
.cc-module:hover{transform:translateY(-2px);border-color:#6366f1}
.cc-module::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,#6366f1,transparent)}
.cc-module-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px}
.cc-module-icon{font-size:32px}
.cc-module-name{font-size:14px;font-weight:700;color:#e2e8f0;margin:8px 0 4px}
.cc-module-status{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.cc-module-status.healthy{background:#22c55e22;color:#22c55e}
.cc-module-status.warning{background:#f59e0b22;color:#f59e0b}
.cc-module-status.degraded{background:#f59e0b22;color:#f59e0b}
.cc-module-status.error{background:#ef444422;color:#ef4444}
.cc-module-status .dot{width:6px;height:6px;border-radius:50%;background:currentColor;box-shadow:0 0 8px currentColor}
.cc-module-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(80px,1fr));gap:8px;margin-bottom:16px}
.cc-stat{background:rgba(15,23,42,.5);border-radius:8px;padding:10px;text-align:center}
.cc-stat-label{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.05em}
.cc-stat-value{font-size:18px;font-weight:800;color:#e2e8f0;margin-top:2px}
.cc-module-link{display:block;text-align:center;padding:10px;background:rgba(99,102,241,.1);border:1px solid #6366f1;border-radius:8px;color:#a5b4fc;text-decoration:none;font-size:12px;font-weight:600;transition:all .2s}
.cc-module-link:hover{background:rgba(99,102,241,.2);color:#fff}
.cc-section{background:rgba(20,25,60,.6);backdrop-filter:blur(10px);border:1px solid #2d3568;border-radius:16px;padding:20px;margin-bottom:20px}
.cc-section h3{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin:0 0 16px;display:flex;align-items:center;gap:8px}
.cc-activity{display:flex;flex-direction:column;gap:8px;max-height:400px;overflow-y:auto}
.cc-activity-item{display:flex;gap:12px;padding:10px;background:rgba(15,23,42,.3);border-radius:8px;font-size:12px}
.cc-activity-time{color:#64748b;font-size:11px;min-width:60px}
.cc-activity-module{color:#a5b4fc;font-weight:600;min-width:80px}
.cc-activity-type{color:#e2e8f0;flex:1}
.cc-quick-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
.cc-action{display:flex;align-items:center;gap:10px;padding:12px;background:rgba(15,23,42,.3);border-radius:10px;text-decoration:none;color:#e2e8f0;transition:all .2s;border:1px solid transparent}
.cc-action:hover{background:rgba(99,102,241,.1);border-color:#6366f1}
.cc-action-icon{font-size:20px}
.cc-action-text{font-size:12px;font-weight:600}

@media(max-width:768px){
  .cc-grid{grid-template-columns:1fr}
  .cc-health{flex-direction:column;text-align:center}
}
</style>

<div class="cc-bg">
  <div class="cc-container">
    <!-- Header -->
    <div class="cc-header">
      <h1><span class="logo">🎛️</span> AI Command Center</h1>
      <div style="display:flex;gap:10px">
        <a href="/" class="cc-module-link" style="padding:8px 16px">Dashboard</a>
        <?php if (!empty($admin)): ?>
          <a href="/admin" class="cc-module-link" style="padding:8px 16px">Admin</a>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- System Health -->
    <div class="cc-health">
      <div>
        <div class="cc-health-label">System Health</div>
        <div class="cc-health-score"><?= e($systemHealth['score']) ?>%</div>
        <div class="cc-health-status">● <?= e($systemHealth['status']) ?></div>
      </div>
      <div class="cc-health-bar">
        <div class="cc-health-fill" style="width:<?= e($systemHealth['score']) ?>%"></div>
      </div>
      <div style="text-align:right;min-width:120px">
        <div style="font-size:11px;color:#6ee7b7;text-transform:uppercase">Modules</div>
        <div style="font-size:14px;font-weight:700;color:#fff">
          <?= (int)$systemHealth['healthy'] ?> / <?= (int)$systemHealth['total'] ?> Healthy
        </div>
        <?php if ($systemHealth['degraded'] > 0): ?>
          <div style="font-size:11px;color:#f59e0b"><?= (int)$systemHealth['degraded'] ?> Degraded</div>
        <?php endif; ?>
        <?php if ($systemHealth['error'] > 0): ?>
          <div style="font-size:11px;color:#ef4444"><?= (int)$systemHealth['error'] ?> Errors</div>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- AI Modules Grid -->
    <div class="cc-grid">
      <?php foreach ($modules as $key => $module): ?>
        <div class="cc-module">
          <div class="cc-module-head">
            <div>
              <div class="cc-module-icon"><?= e($module['icon']) ?></div>
              <div class="cc-module-name"><?= e($module['name']) ?></div>
            </div>
            <span class="cc-module-status <?= e($module['status']) ?>">
              <span class="dot"></span>
              <?= e(strtoupper($module['status'])) ?>
            </span>
          </div>
          
          <?php if (!empty($module['stats'])): ?>
            <div class="cc-module-stats">
              <?php foreach ($module['stats'] as $label => $value): ?>
                <div class="cc-stat">
                  <div class="cc-stat-label"><?= e(ucwords(str_replace('_', ' ', $label))) ?></div>
                  <div class="cc-stat-value">
                    <?php if ($value === null): ?>
                      N/A
                    <?php elseif (is_bool($value)): ?>
                      <?= $value ? 'YES' : 'NO' ?>
                    <?php elseif (is_numeric($value) && $value > 1000000): ?>
                      <?= e(number_format($value / 1000000, 1)) ?>M
                    <?php elseif (is_numeric($value) && $value > 1000): ?>
                      <?= e(number_format($value / 1000, 0)) ?>K
                    <?php else: ?>
                      <?= e(is_numeric($value) ? number_format($value) : $value) ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          
          <?php if (!empty($module['error'])): ?>
            <div style="background:#ef444422;border:1px solid #ef4444;border-radius:8px;padding:10px;margin-bottom:12px;font-size:11px;color:#fca5a5">
              <?= e($module['error']) ?>
            </div>
          <?php endif; ?>
          
          <a href="<?= e($module['link']) ?>" class="cc-module-link">
            Open Module →
          </a>
        </div>
      <?php endforeach; ?>
    </div>
    
    <!-- Quick Actions -->
    <div class="cc-section">
      <h3>⚡ Quick Actions</h3>
      <div class="cc-quick-actions">
        <a href="/app/agent-platform" class="cc-action">
          <span class="cc-action-icon">⚡</span>
          <span class="cc-action-text">Agent Platform</span>
        </a>
        <a href="/app/workforce" class="cc-action">
          <span class="cc-action-icon">🤖</span>
          <span class="cc-action-text">AI Workforce</span>
        </a>
        <a href="/multiplier" class="cc-action">
          <span class="cc-action-icon">🚀</span>
          <span class="cc-action-text">Multiplier AI</span>
        </a>
        <a href="/lottery" class="cc-action">
          <span class="cc-action-icon">🎰</span>
          <span class="cc-action-text">Lottery Intel</span>
        </a>
        <a href="/app/trading" class="cc-action">
          <span class="cc-action-icon">💹</span>
          <span class="cc-action-text">Trading</span>
        </a>
        <a href="/sports" class="cc-action">
          <span class="cc-action-icon">⚽</span>
          <span class="cc-action-text">Sports Intel</span>
        </a>
        <?php if (!empty($admin)): ?>
        <a href="/admin/api" class="cc-action">
          <span class="cc-action-icon">🔧</span>
          <span class="cc-action-text">AI Providers</span>
        </a>
        <a href="/multiplier/admin" class="cc-action">
          <span class="cc-action-icon">🎛️</span>
          <span class="cc-action-text">Module Admin</span>
        </a>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Recent Activity -->
    <div class="cc-section">
      <h3>📊 Recent Activity</h3>
      <?php if (empty($recentActivity)): ?>
        <div style="text-align:center;padding:20px;color:#64748b">No recent activity</div>
      <?php else: ?>
        <div class="cc-activity">
          <?php foreach (array_slice($recentActivity, 0, 15) as $activity): ?>
            <div class="cc-activity-item">
              <div class="cc-activity-time"><?= e(substr($activity['time'] ?? '', 11, 8)) ?></div>
              <div class="cc-activity-module"><?= e($activity['module']) ?></div>
              <div class="cc-activity-type"><?= e($activity['type']) ?> — <?= e(mb_substr($activity['summary'] ?? '', 0, 60)) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

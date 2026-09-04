<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $config @var array $stats @var array $providers @var array $models @var array $recentPredictions @var array $accuracyHistory */
?>
<style>
.ma-bg{background:#0a0e27;min-height:100vh;padding:20px}
.ma-container{max-width:1200px;margin:0 auto}
.ma-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;padding:20px;background:linear-gradient(135deg,#1e1b4b 0%,#312e81 100%);border-radius:16px;border:1px solid #4c1d95}
.ma-header h1{font-size:24px;font-weight:800;color:#fff;margin:0;display:flex;align-items:center;gap:10px}
.ma-section{background:rgba(20,25,60,.6);backdrop-filter:blur(10px);border:1px solid #2d3568;border-radius:16px;padding:20px;margin-bottom:20px}
.ma-section h3{font-size:14px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin:0 0 16px;padding-bottom:8px;border-bottom:1px solid #2d3568}
.ma-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px}
.ma-stat{background:rgba(15,23,42,.5);border-radius:12px;padding:16px;border:1px solid #1e293b}
.ma-stat-label{font-size:11px;color:#64748b;text-transform:uppercase;margin-bottom:4px}
.ma-stat-value{font-size:28px;font-weight:800;color:#e2e8f0}
.ma-form{display:flex;flex-direction:column;gap:12px}
.ma-field{display:flex;flex-direction:column;gap:4px}
.ma-field label{font-size:12px;font-weight:600;color:#94a3b8}
.ma-field input,.ma-field select{background:#0f172a;border:1px solid #1e293b;border-radius:8px;padding:10px;color:#e2e8f0;font-size:13px}
.ma-field input:focus,.ma-field select:focus{outline:none;border-color:#6366f1}
.ma-toggle{display:flex;align-items:center;gap:10px}
.ma-toggle input[type="checkbox"]{width:18px;height:18px;accent-color:#6366f1}
.ma-btn{padding:12px 24px;background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%);color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;transition:all .2s}
.ma-btn:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(99,102,241,.4)}
.ma-btn-danger{background:linear-gradient(135deg,#ef4444 0%,#dc2626 100%)}
.ma-btn-secondary{background:#1e293b}
.ma-table{width:100%;border-collapse:collapse}
.ma-table th{text-align:left;padding:10px;background:#0f172a;color:#94a3b8;font-size:11px;text-transform:uppercase;border-bottom:1px solid #1e293b}
.ma-table td{padding:10px;border-bottom:1px solid #1e293b;color:#e2e8f0;font-size:13px}
.ma-table tr:hover{background:rgba(15,23,42,.3)}
.ma-badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700}
.ma-badge-success{background:#22c55e22;color:#22c55e}
.ma-badge-warning{background:#f59e0b22;color:#f59e0b}
.ma-badge-error{background:#ef444422;color:#ef4444}
.ma-provider{display:flex;align-items:center;justify-content:space-between;padding:12px;background:rgba(15,23,42,.3);border-radius:8px;margin-bottom:8px}
</style>

<div class="ma-bg">
  <div class="ma-container">
    <!-- Header -->
    <div class="ma-header">
      <h1>🎛️ Multiplier Intelligence Admin</h1>
      <div style="display:flex;gap:10px">
        <a href="/multiplier" class="ma-btn ma-btn-secondary">View Dashboard</a>
        <a href="/admin" class="ma-btn ma-btn-secondary">Admin Home</a>
      </div>
    </div>
    
    <!-- Stats -->
    <div class="ma-section">
      <h3>📊 Module Statistics</h3>
      <div class="ma-grid">
        <div class="ma-stat">
          <div class="ma-stat-label">Total Predictions</div>
          <div class="ma-stat-value"><?= number_format($stats['total_predictions'] ?? 0) ?></div>
        </div>
        <div class="ma-stat">
          <div class="ma-stat-label">Validated</div>
          <div class="ma-stat-value"><?= number_format($stats['validated_predictions'] ?? 0) ?></div>
        </div>
        <div class="ma-stat">
          <div class="ma-stat-label">Active Agents</div>
          <div class="ma-stat-value"><?= $stats['total_agents'] ?? 9 ?></div>
        </div>
        <div class="ma-stat">
          <div class="ma-stat-label">Accuracy (Last 100)</div>
          <div class="ma-stat-value"><?= $stats['accuracy_100'] !== null ? number_format($stats['accuracy_100'], 1) . '%' : 'N/A' ?></div>
        </div>
      </div>
    </div>
    
    <!-- Configuration -->
    <div class="ma-section">
      <h3>⚙️ Configuration</h3>
      <form method="POST" action="/multiplier_admin/save_config" class="ma-form">
        <div class="ma-toggle">
          <input type="checkbox" name="enabled" id="enabled" <?= !empty($config['enabled']) ? 'checked' : '' ?>>
          <label for="enabled">Enable Multiplier Intelligence Module</label>
        </div>
        
        <div class="ma-field">
          <label>Active Provider</label>
          <select name="active_provider">
            <?php foreach ($providers as $p): ?>
              <option value="<?= e($p['code']) ?>" <?= ($config['active_provider'] ?? '') === $p['code'] ? 'selected' : '' ?>>
                <?= e($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="ma-grid" style="grid-template-columns:1fr 1fr">
          <div class="ma-field">
            <label>Signal Interval (seconds)</label>
            <input type="number" name="signal_interval" value="<?= (int)($config['signal_interval'] ?? 30) ?>" min="10" max="300">
          </div>
          
          <div class="ma-field">
            <label>History Size (rounds)</label>
            <input type="number" name="history_size" value="<?= (int)($config['history_size'] ?? 200) ?>" min="50" max="1000">
          </div>
          
          <div class="ma-field">
            <label>Max Signals Per Hour</label>
            <input type="number" name="max_signals_per_hour" value="<?= (int)($config['max_signals_per_hour'] ?? 120) ?>" min="10" max="1000">
          </div>
        </div>
        
        <div class="ma-toggle">
          <input type="checkbox" name="require_disclaimer" id="require_disclaimer" <?= !empty($config['require_disclaimer']) ? 'checked' : '' ?>>
          <label for="require_disclaimer">Require disclaimer display</label>
        </div>
        
        <div class="ma-toggle">
          <input type="checkbox" name="allow_anonymous" id="allow_anonymous" <?= !empty($config['allow_anonymous']) ? 'checked' : '' ?>>
          <label for="allow_anonymous">Allow anonymous access</label>
        </div>
        
        <div class="ma-toggle">
          <input type="checkbox" name="enable_accuracy_tracking" id="enable_accuracy_tracking" <?= !empty($config['enable_accuracy_tracking']) ? 'checked' : '' ?>>
          <label for="enable_accuracy_tracking">Enable accuracy tracking</label>
        </div>
        
        <div style="display:flex;gap:10px;margin-top:16px">
          <button type="submit" class="ma-btn">Save Configuration</button>
          <button type="button" class="ma-btn ma-btn-secondary" onclick="location.reload()">Reset</button>
        </div>
      </form>
    </div>
    
    <!-- Providers -->
    <div class="ma-section">
      <h3>🔌 Providers</h3>
      <?php foreach ($providers as $p): ?>
        <div class="ma-provider">
          <div>
            <div style="font-weight:700;color:#e2e8f0"><?= e($p['name']) ?></div>
            <div style="font-size:11px;color:#64748b"><?= e($p['description']) ?></div>
          </div>
          <span class="ma-badge <?= $p['enabled'] ? 'ma-badge-success' : 'ma-badge-error' ?>">
            <?= $p['enabled'] ? 'Enabled' : 'Disabled' ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
    
    <!-- Models -->
    <div class="ma-section">
      <h3>🤖 AI Models</h3>
      <table class="ma-table">
        <thead>
          <tr>
            <th>Model Code</th>
            <th>Name</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($models as $m): ?>
            <tr>
              <td><code style="color:#a5b4fc"><?= e($m['code']) ?></code></td>
              <td><?= e($m['name']) ?></td>
              <td>
                <span class="ma-badge <?= $m['enabled'] ? 'ma-badge-success' : 'ma-badge-error' ?>">
                  <?= $m['enabled'] ? 'Active' : 'Inactive' ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    
    <!-- Recent Predictions -->
    <div class="ma-section">
      <h3>📈 Recent Predictions</h3>
      <?php if (empty($recentPredictions)): ?>
        <div style="text-align:center;padding:20px;color:#64748b">No predictions yet</div>
      <?php else: ?>
        <table class="ma-table">
          <thead>
            <tr>
              <th>Time</th>
              <th>Predicted</th>
              <th>Actual</th>
              <th>Error</th>
              <th>Confidence</th>
              <th>Risk</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($recentPredictions, 0, 10) as $p): 
              $errorPct = $p['error_pct'] ?? null;
              $status = $p['validated'] ? 'Validated' : 'Pending';
              $statusCls = $p['validated'] ? 'ma-badge-success' : 'ma-badge-warning';
            ?>
              <tr>
                <td><?= e(substr($p['predicted_at'] ?? '', 0, 16)) ?></td>
                <td style="font-weight:700;color:#a5b4fc"><?= e(number_format($p['predicted_multiplier'] ?? 0, 2)) ?>x</td>
                <td><?= $p['actual_multiplier'] !== null ? e(number_format($p['actual_multiplier'], 2)) . 'x' : '—' ?></td>
                <td><?= $errorPct !== null ? e(number_format($errorPct, 1)) . '%' : '—' ?></td>
                <td><?= e(number_format(($p['confidence'] ?? 0) * 100, 0)) ?>%</td>
                <td>
                  <span class="ma-badge ma-badge-<?= strtolower($p['risk_level'] ?? 'medium') === 'low' ? 'success' : (strtolower($p['risk_level'] ?? '') === 'high' ? 'error' : 'warning') ?>">
                    <?= e($p['risk_level'] ?? 'MEDIUM') ?>
                  </span>
                </td>
                <td><span class="ma-badge <?= $statusCls ?>"><?= $status ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      
      <div style="display:flex;gap:10px;margin-top:16px">
        <form method="POST" action="/multiplier_admin/validate_predictions" style="display:inline">
          <button type="submit" class="ma-btn ma-btn-secondary">Validate Pending Predictions</button>
        </form>
      </div>
    </div>
    
    <!-- Danger Zone -->
    <div class="ma-section" style="border-color:#ef4444">
      <h3 style="color:#ef4444">⚠️ Danger Zone</h3>
      <p style="color:#94a3b8;font-size:13px;margin-bottom:16px">
        These actions are irreversible. Proceed with caution.
      </p>
      <form method="POST" action="/multiplier_admin/reset" onsubmit="return confirm('Type RESET in the prompt to confirm deletion of all prediction data.');">
        <div class="ma-field" style="max-width:300px;margin-bottom:12px">
          <label>Type RESET to confirm</label>
          <input type="text" name="confirm" placeholder="RESET" required>
        </div>
        <button type="submit" class="ma-btn ma-btn-danger">Reset All Prediction Data</button>
      </form>
    </div>
  </div>
</div>

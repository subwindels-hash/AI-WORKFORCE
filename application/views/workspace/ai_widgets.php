
<?php
// Calculate AI system health
$_aiHealth = 0;
$_aiTotal = count($aiModules ?? []);
$_aiHealthy = 0;
foreach ($aiModules ?? [] as $_m) {
  if (($_m['status'] ?? '') === 'healthy') $_aiHealthy++;
}
$_aiHealth = $_aiTotal > 0 ? round(($_aiHealthy / $_aiTotal) * 100, 0) : 0;
?>

<h2 class="section-title">AI system overview <a href="/command-center" style="font-size:12px;font-weight:400;color:var(--dim);text-decoration:none;margin-left:8px">Open Command Center →</a></h2>
<section class="panel" style="margin-bottom:18px">
  <div class="body">
    <!-- Health Score Bar -->
    <div style="display:flex;align-items:center;gap:20px;margin-bottom:20px;padding:16px;background:linear-gradient(135deg,rgba(16,185,129,.1),rgba(34,197,94,.05));border:1px solid rgba(16,185,129,.2);border-radius:var(--radius)">
      <div style="text-align:center;min-width:80px">
        <div style="font-size:36px;font-weight:800;color:var(--green)"><?= (int)$_aiHealth ?>%</div>
        <div style="font-size:10px;color:var(--dim);text-transform:uppercase;letter-spacing:.06em">System Health</div>
      </div>
      <div style="flex:1">
        <div style="font-size:13px;font-weight:600;color:#fff;margin-bottom:6px">All AI Modules Operational</div>
        <div style="height:6px;background:var(--panel2);border-radius:3px;overflow:hidden">
          <div style="height:100%;width:<?= (int)$_aiHealth ?>%;background:linear-gradient(90deg,var(--green),#22c55e);transition:width .3s"></div>
        </div>
        <div style="font-size:11px;color:var(--dim);margin-top:6px"><?= (int)$_aiHealthy ?> of <?= (int)$_aiTotal ?> modules healthy</div>
      </div>
    </div>
    
    <!-- Module Grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">
      <?php foreach ($aiModules ?? [] as $key => $module): ?>
        <?php $_link = '/command-center';
        if ($key === 'windelsai') $_link = '/app/agent-platform';
        elseif ($key === 'multiplier') $_link = '/multiplier';
        elseif ($key === 'lottery') $_link = '/lottery';
        elseif ($key === 'trading') $_link = '/app/trading';
        elseif ($key === 'language') $_link = '/app/languages';
        elseif ($key === 'sports') $_link = '/sports';
        elseif ($key === 'leads') $_link = '/leads';
        ?>
        <a href="<?= e($_link) ?>" style="text-decoration:none;color:inherit;background:var(--panel2);border:1px solid var(--line);border-radius:var(--radius-sm);padding:14px;transition:all .2s;display:block" onmouseover="this.style.borderColor='var(--brand)';this.style.transform='translateY(-2px)'" onmouseout="this.style.borderColor='var(--line)';this.style.transform='none'">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
            <span style="font-size:24px"><?= e($module['icon'] ?? '🔧') ?></span>
            <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
              <?= ($module['status'] ?? '') === 'healthy' ? 'background:rgba(16,185,129,.15);color:var(--green)' : (($module['status'] ?? '') === 'warning' ? 'background:rgba(245,158,11,.15);color:var(--amber)' : 'background:rgba(239,68,68,.15);color:var(--red)') ?>">
              <span style="width:5px;height:5px;border-radius:50%;background:currentColor;box-shadow:0 0 6px currentColor"></span>
              <?= e(strtoupper($module['status'] ?? 'unknown')) ?>
            </span>
          </div>
          <div style="font-size:12px;font-weight:600;color:#fff;margin-bottom:4px"><?= e($module['name'] ?? '') ?></div>
          <?php if (!empty($module['agents'])): ?>
            <div style="font-size:10px;color:var(--dim)"><?= (int)$module['agents'] ?> agents<?= !empty($module['tools']) ? ' · ' . (int)$module['tools'] . ' tools' : '' ?></div>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<h2 class="section-title">Multiplier intelligence</h2>
<section class="panel" style="margin-bottom:18px">
  <div class="body">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px">
      <div>
        <h3 style="margin:0 0 4px">🚀 AI Multiplier Intelligence</h3>
        <p class="dim" style="margin:0">9 specialist AI agents analyzing crash-game patterns with transparent accuracy tracking.</p>
      </div>
      <div style="display:flex;gap:8px">
        <a class="btn primary" href="/multiplier">Open Command Center</a>
        <?php if (!empty($admin)): ?>
          <a class="btn" href="/multiplier/admin">Admin</a>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Multiplier Stats Grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:16px">
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Specialist Agents</div>
        <div style="font-size:20px;font-weight:700;color:#fff">9</div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Rounds Analyzed</div>
        <div style="font-size:20px;font-weight:700;color:#fff"><?= (int)($multiplierWidget['historyCount'] ?? 0) ?></div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Accuracy (±20%)</div>
        <?php $acc = $multiplierWidget['accuracy20'] ?? null; ?>
        <div style="font-size:20px;font-weight:700;color:<?= $acc !== null ? 'var(--green)' : 'var(--dim)' ?>"><?= $acc !== null ? number_format($acc, 1) . '%' : 'N/A' ?></div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Predictions</div>
        <div style="font-size:20px;font-weight:700;color:#fff"><?= (int)($multiplierWidget['totalPredictions'] ?? 0) ?></div>
      </div>
    </div>
    
    <!-- Last Signal -->
    <?php $signal = $multiplierWidget['lastSignal'] ?? null; ?>
    <?php if ($signal): ?>
    <div style="background:linear-gradient(135deg,rgba(99,102,241,.1),rgba(139,92,246,.05));border:1px solid rgba(99,102,241,.2);border-radius:var(--radius-sm);padding:16px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div style="font-size:12px;font-weight:600;color:var(--brand);text-transform:uppercase;letter-spacing:.06em">Latest AI Signal</div>
        <span class="statuspill" style="font-size:10px"><i class="pill-dot"></i><?= e(strtoupper($signal['risk'] ?? 'MEDIUM')) ?> Risk</span>
      </div>
      <div style="display:flex;align-items:baseline;gap:16px;flex-wrap:wrap">
        <div>
          <div style="font-size:11px;color:var(--dim)">Predicted Multiplier</div>
          <div style="font-size:28px;font-weight:800;color:#fff"><?= e(number_format($signal['predicted'] ?? 0, 2)) ?>x</div>
        </div>
        <div>
          <div style="font-size:11px;color:var(--dim)">Confidence</div>
          <div style="font-size:28px;font-weight:800;color:var(--brand)"><?= e(number_format(($signal['confidence'] ?? 0) * 100, 0)) ?>%</div>
        </div>
        <div style="flex:1"></div>
        <div style="font-size:11px;color:var(--dim);text-align:right">
          Generated <?= e(date('H:i:s', strtotime($signal['generatedAt'] ?? 'now'))) ?>
        </div>
      </div>
      <div style="font-size:10px;color:var(--dim);margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
        ⚠️ Educational purpose only — crash games are random. No prediction is guaranteed.
      </div>
    </div>
    <?php else: ?>
    <p class="dim">No signals generated yet. <a href="/multiplier">Open Multiplier Intelligence →</a></p>
    <?php endif; ?>
  </div>
</section>

<h2 class="section-title">Language learning</h2>
<section class="panel" style="margin-bottom:18px">
  <div class="body">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px">
      <div>
        <h3 style="margin:0 0 4px">🗣️ AI Language Teacher</h3>
        <p class="dim" style="margin:0">Learn any of 20+ languages with AI-powered translation, listening, and speaking practice.</p>
      </div>
      <div style="display:flex;gap:8px">
        <a class="btn primary" href="/app/languages">Open Language Hub</a>
        <a class="btn" href="/app/languages/teacher">Start Lesson</a>
      </div>
    </div>
    
    <?php $langProfiles = $languageWidget['profiles'] ?? []; ?>
    <?php if (!empty($langProfiles)): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:14px">
      <?php foreach ($langProfiles as $profile): ?>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:var(--radius-sm);padding:14px">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
            <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--brand),#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#fff">
              <?= e(strtoupper(substr($profile['target_language'] ?? $profile['targetLanguage'] ?? '?', 0, 2))) ?>
            </div>
            <div>
              <div style="font-size:13px;font-weight:600;color:#fff"><?= e(ucfirst($profile['target_language'] ?? $profile['targetLanguage'] ?? 'Language')) ?></div>
              <div style="font-size:11px;color:var(--dim)"><?= e($profile['native_language'] ?? $profile['nativeLanguage'] ?? '') ?> → <?= e($profile['target_language'] ?? $profile['targetLanguage'] ?? '') ?></div>
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--dim)">
            <span>Level: <?= e($profile['level'] ?? 'Beginner') ?></span>
            <span>Profile #<?= e($profile['id'] ?? '?') ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (($languageWidget['totalProfiles'] ?? 0) > 3): ?>
      <p class="dim">+ <?= (int)$languageWidget['totalProfiles'] - 3 ?> more language profile<?= (int)$languageWidget['totalProfiles'] - 3 === 1 ? '' : 's' ?>. <a href="/app/languages">View all →</a></p>
    <?php endif; ?>
    <?php else: ?>
    <div style="text-align:center;padding:20px">
      <p class="dim" style="margin-bottom:12px">No language profiles yet. Start learning today!</p>
      <a class="btn primary" href="/app/languages/teacher">Create your first profile</a>
    </div>
    <?php endif; ?>
  </div>
</section>

<h2 class="section-title">Sports intelligence</h2>
<section class="panel" style="margin-bottom:18px">
  <div class="body">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px">
      <div>
        <h3 style="margin:0 0 4px">⚽ AI Sports Intelligence</h3>
        <p class="dim" style="margin:0">Fixtures, odds and ticket research from connected sports data — never invented when a feed is missing.</p>
      </div>
      <div style="display:flex;gap:8px">
        <a class="btn primary" href="/sports">Open Sports Intel</a>
      </div>
    </div>
    
    <?php $sportsProviders = $sportsWidget['providers'] ?? []; ?>
    <?php if (!empty($sportsProviders)): ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
      <?php foreach (array_values($sportsProviders) as $i => $sp): ?>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:var(--radius-sm);padding:10px 14px;display:flex;align-items:center;gap:8px">
          <span style="width:8px;height:8px;border-radius:50%;background:<?= !empty($sp['healthy']) ? 'var(--green)' : 'var(--dim)' ?>;box-shadow:0 0 6px <?= !empty($sp['healthy']) ? 'var(--green)' : 'var(--dim)' ?>"></span>
          <span style="font-size:12px;font-weight:600;color:#fff">Feed <?= (int) $i + 1 ?></span>
          <?php if (!empty($sp['healthy'])): ?>
            <span style="font-size:10px;color:var(--green);text-transform:uppercase">Connected</span>
          <?php else: ?>
            <span style="font-size:10px;color:var(--dim)">Inactive</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="dim">No sports data connected yet. <a href="/sports">Open Sports Intel →</a></p>
    <?php endif; ?>
    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px">
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Live feeds</div>
        <div style="font-size:20px;font-weight:700;color:#fff"><?= (int)($sportsWidget['totalProviders'] ?? 0) ?></div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">API Status</div>
        <div style="font-size:20px;font-weight:700;color:<?= ($sportsWidget['status'] ?? '') === 'ok' ? 'var(--green)' : 'var(--dim)' ?>"><?= e(strtoupper($sportsWidget['status'] ?? 'NO_DATA')) ?></div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Leagues</div>
        <div style="font-size:20px;font-weight:700;color:#fff">1000+</div>
      </div>
    </div>
  </div>
</section>

<h2 class="section-title">Windels AI Agents</h2>
<section class="panel" style="margin-bottom:18px">
  <div class="body">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px">
      <div>
        <h3 style="margin:0 0 4px">⚡ Windels AI Agents</h3>
        <p class="dim" style="margin:0">Specialized AI agents working together with tools, workflows, and multi-model routing.</p>
      </div>
      <div style="display:flex;gap:8px">
        <a class="btn primary" href="/app/agent-platform">Open Platform</a>
        <a class="btn" href="/app/workforce">AI Workforce</a>
      </div>
    </div>
    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:14px">
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">AI Agents</div>
        <div style="font-size:20px;font-weight:700;color:#fff"><?= (int)($windelsAI['totalAgents'] ?? 0) ?></div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">MCP Tools</div>
        <div style="font-size:20px;font-weight:700;color:#fff"><?= (int)($windelsAI['totalTools'] ?? 0) ?></div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Model Providers</div>
        <div style="font-size:20px;font-weight:700;color:#fff"><?= count($windelsAI['modelProviders'] ?? []) ?></div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Platform Status</div>
        <div style="font-size:20px;font-weight:700;color:var(--green)"><?= !empty($windelsAI['totalAgents']) ? 'LIVE' : '—' ?></div>
      </div>
    </div>
    
    <?php if (!empty($windelsAI['agents'])): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php foreach (array_slice($windelsAI['agents'], 0, 6) as $agent): ?>
        <span class="statuspill" style="font-size:10px">
          <i class="pill-dot" style="background:var(--green)"></i>
          <?= e(is_array($agent) ? ($agent['name'] ?? $agent['id'] ?? 'Agent') : (string)$agent) ?>
        </span>
      <?php endforeach; ?>
      <?php if (($windelsAI['totalAgents'] ?? 0) > 6): ?>
        <span class="statuspill" style="font-size:10px;opacity:.6">+<?= (int)$windelsAI['totalAgents'] - 6 ?> more</span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>


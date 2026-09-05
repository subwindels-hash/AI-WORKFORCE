<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $stats @var array $smtp */
$s = $stats ?? [];
?>
<div class="page-head">
  <div>
    <p class="eyebrow">WINDELS AI WORKFORCE</p>
    <h2>Administrator dashboard</h2>
    <p>Live platform overview from the application database. Empty numbers mean no matching records yet.</p>
  </div>
  <div class="admin-badge"><img src="/assets/images/ai-agent-avatar.png" alt="" width="28" height="28"><span>Admin mode</span></div>
</div>

<div class="grid four">
  <a class="kp-card" href="/admin/users">
    <div class="kp-top"><div class="k">Total users</div></div>
    <div class="v"><?= (int) ($s['users'] ?? 0) ?></div>
    <div class="trend">Accounts in the directory</div>
  </a>
  <a class="kp-card" href="/admin/users?status=active">
    <div class="kp-top"><div class="k">Active users</div></div>
    <div class="v"><?= (int) ($s['active'] ?? 0) ?></div>
    <div class="trend">Can sign in</div>
  </a>
  <a class="kp-card" href="/admin/users?status=suspended">
    <div class="kp-top"><div class="k">Suspended</div></div>
    <div class="v"><?= (int) ($s['suspended'] ?? 0) ?></div>
    <div class="trend">Blocked from the dashboard</div>
  </a>
  <div class="kp-card">
    <div class="kp-top"><div class="k">New users (7 days)</div></div>
    <div class="v"><?= (int) ($s['newUsers'] ?? 0) ?></div>
    <div class="trend">Created in the last week</div>
  </div>
</div>

<div class="grid four" style="margin-top:16px">
  <a class="kp-card" href="/admin/workforce">
    <div class="kp-top"><div class="k">AI usage</div></div>
    <div class="v"><?= (int) ($s['aiUsage'] ?? 0) ?></div>
    <div class="trend">Stored analysis runs</div>
  </a>
  <a class="kp-card" href="/admin/languages">
    <div class="kp-top"><div class="k">Language learning</div></div>
    <div class="v"><?= (int) ($s['languageSessions'] ?? 0) ?></div>
    <div class="trend"><?= (int) ($s['languageProfiles'] ?? 0) ?> profiles</div>
  </a>
  <a class="kp-card" href="/admin/conversations">
    <div class="kp-top"><div class="k">Conversations</div></div>
    <div class="v"><?= (int) ($s['conversations'] ?? 0) ?></div>
    <div class="trend">Teacher sessions stored</div>
  </a>
  <a class="kp-card" href="/admin/inbox">
    <div class="kp-top"><div class="k">Inbox messages</div></div>
    <div class="v"><?= (int) ($s['inboxTotal'] ?? 0) ?><?php if ((int) ($s['inboxUnread'] ?? 0) > 0): ?> <span style="color:#dc2626;font-size:14px;font-weight:600">(<?= (int) $s['inboxUnread'] ?> unread)</span><?php endif; ?></div>
    <div class="trend">Contact-form submissions — reply from the inbox</div>
  </a>
  <div class="kp-card">
    <div class="kp-top"><div class="k">Recent logins (30 days)</div></div>
    <div class="v"><?= (int) ($s['recentLogins'] ?? 0) ?></div>
    <div class="trend">Active accounts with a recorded login</div>
  </div>
</div>

<?php $su = is_array($signup ?? null) ? $signup : null; if ($su !== null && admin_can('admin.settings.manage')): ?>
<section class="panel" style="margin-top:16px" id="signup-protection">
  <h3>Sign-up protection</h3>
  <div class="body">
    <div class="stat-grid">
      <div class="stat"><div class="k">Public registration</div><div class="v"><span class="badge <?= !empty($su['registrationEnabled']) ? 'b-green' : 'b-gray' ?>"><?= !empty($su['registrationEnabled']) ? 'OPEN' : 'CLOSED' ?></span></div></div>
      <div class="stat"><div class="k">reCAPTCHA</div><div class="v"><span class="badge <?= ($su['captchaState'] ?? 'OFF') === 'ACTIVE' ? 'b-green' : (($su['captchaState'] ?? 'OFF') === 'MISCONFIGURED' ? 'b-red' : 'b-gray') ?>"><?= e((string) ($su['captchaState'] ?? 'OFF')) ?></span></div><div class="trend"><?= e((string) ($su['captchaLabel'] ?? 'Off')) ?></div></div>
      <div class="stat"><div class="k">Email validation</div><div class="v" style="font-size:13px"><?= ($su['emailMode'] ?? 'mx') === 'mx' ? 'Syntax + MX' : 'Syntax' ?></div><div class="trend"><?= !empty($su['blockDisposable']) ? 'disposable blocked' : 'disposable allowed' ?></div></div>
      <div class="stat"><div class="k">Domain rules</div><div class="v" style="font-size:13px"><?= (int) ($su['blockedDomains'] ?? 0) ?> blocked · <?= (int) ($su['allowedDomains'] ?? 0) ?> allowed</div></div>
    </div>
    <?php if (!empty($su['signupBlocked'])): ?><div class="notice err" style="margin-top:10px"><b>Sign-up is blocked:</b> reCAPTCHA is on but a key is missing. Fix it in System Settings → Sign-up Protection.</div><?php endif; ?>
  </div>
  <a class="panel-foot-link" href="/admin/settings#signup">Configure sign-up protection →</a>
</section>
<?php endif; ?>

<section class="panel" style="margin-top:16px">
  <h3>🤖 Cloudflare Agent Runtime</h3>
  <div class="body">
    <?php if (!empty($agentRuntime['cloudflareRuntime'])): ?>
      <!-- Cloudflare Runtime Active -->
      <div style="background:linear-gradient(135deg,#f6821f22 0%,#fbad4122 100%);border:1px solid #f6821f44;border-radius:var(--radius);padding:16px;margin-bottom:16px">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
          <span style="font-size:32px">⚡</span>
          <div>
            <h4 style="margin:0;color:#f6821f;font-size:16px">Cloudflare Workers AI Active</h4>
            <p class="dim" style="margin:2px 0 0;font-size:12px">Edge AI inference powered by Cloudflare's global network</p>
          </div>
        </div>
        
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
          <div style="background:#ffffff11;border-radius:var(--radius-sm);padding:12px">
            <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Account ID</div>
            <div style="font-size:14px;font-weight:600;color:#fff"><?= e($agentRuntime['cloudflareRuntime']['accountId'] ?? '—') ?></div>
          </div>
          <div style="background:#ffffff11;border-radius:var(--radius-sm);padding:12px">
            <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Agents</div>
            <div style="font-size:14px;font-weight:600;color:#fff"><?= count($agentRuntime['registeredAgents'] ?? []) ?></div>
          </div>
          <div style="background:#ffffff11;border-radius:var(--radius-sm);padding:12px">
            <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Tools</div>
            <div style="font-size:14px;font-weight:600;color:#fff"><?= count($agentRuntime['registeredTools'] ?? []) ?></div>
          </div>
          <div style="background:#ffffff11;border-radius:var(--radius-sm);padding:12px">
            <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Tool Policy</div>
            <div style="font-size:14px;font-weight:600;color:#fff">Approval Required</div>
          </div>
        </div>
      </div>
      
      <!-- Registered Agents -->
      <?php if (!empty($agentRuntime['registeredAgents'])): ?>
      <div style="margin-bottom:16px">
        <h4 style="margin:0 0 8px;font-size:13px;font-weight:600">Registered AI Agents</h4>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php foreach ($agentRuntime['registeredAgents'] as $agent): ?>
            <span style="background:#f6821f22;color:#f6821f;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600"><?= e(ucfirst(str_replace('_', ' ', $agent))) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      
      <!-- Available Services -->
      <?php if (!empty($agentRuntime['availableServices'])): ?>
      <div style="margin-bottom:16px">
        <h4 style="margin:0 0 8px;font-size:13px;font-weight:600">Supported AI Capabilities</h4>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px">
          <?php foreach ($agentRuntime['availableServices'] as $service => $description): ?>
            <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:10px">
              <div style="font-size:12px;font-weight:600;color:#fff;margin-bottom:2px"><?= e(ucfirst(str_replace('_', ' ', $service))) ?></div>
              <div style="font-size:11px;color:var(--dim)"><?= e($description) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
      
      <div style="display:flex;gap:8px;margin-top:12px">
        <a class="btn primary" href="/admin/api">Manage AI Providers</a>
        <a class="btn" href="https://developers.cloudflare.com/workers-ai/" target="_blank">Cloudflare AI Docs</a>
      </div>
      
    <?php else: ?>
      <!-- Cloudflare Not Configured -->
      <div style="text-align:center;padding:30px 0">
        <div style="font-size:48px;margin-bottom:12px">⚡</div>
        <h4 style="margin:0 0 8px;color:var(--text)">Cloudflare Workers AI Not Configured</h4>
        <p class="dim" style="margin:0 0 16px;font-size:13px">
          Enable edge AI inference with Cloudflare Workers AI for LLMs, embeddings, image generation, and more.
        </p>
        <a class="btn primary" href="/admin/api/create">Configure Cloudflare</a>
      </div>
      
      <div style="margin-top:16px;padding:14px;background:var(--panel2);border-radius:var(--radius-sm)">
        <h5 style="margin:0 0 8px;font-size:12px;font-weight:600">What you get with Cloudflare Workers AI:</h5>
        <ul style="margin:0;padding-left:20px;font-size:12px;color:var(--dim);line-height:1.8">
          <li><strong>Text Generation</strong> — Llama 3.1, Mistral, Gemma, Phi-2</li>
          <li><strong>Embeddings</strong> — BGE for semantic search and RAG</li>
          <li><strong>Image Generation</strong> — Stable Diffusion XL</li>
          <li><strong>Speech Recognition</strong> — Whisper transcription</li>
          <li><strong>Translation</strong> — 100+ languages with M2M100</li>
          <li><strong>Summarization</strong> — BART text summarization</li>
          <li><strong>Classification</strong> — Sentiment analysis</li>
          <li><strong>Edge Inference</strong> — Low latency, global distribution</li>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</section>

<div class="grid cols-main" style="margin-top:16px">
  <section class="panel">
    <h3>Recent registrations</h3>
    <div class="body table-scroll">
      <?php $recent = $s['recentUsers'] ?? []; ?>
      <?php if (!$recent): ?>
        <div class="empty-state"><p>No user accounts have been created yet.</p></div>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>User ID</th><th>Username</th><th>Email</th><th>Status</th><th>Created</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($recent as $u): ?>
              <tr>
                <td class="mono"><a href="/admin/users/<?= (int) $u['id'] ?>"><?= e($u['user_uid'] ?? '') ?></a></td>
                <td><?= e($u['username'] ?? $u['display_name'] ?? '') ?></td>
                <td class="dim"><?= e($u['email'] ?? '') ?></td>
                <td><span class="badge <?= !empty($u['active']) ? 'b-green' : 'b-gray' ?>"><?= !empty($u['active']) ? 'Active' : 'Suspended' ?></span></td>
                <td class="dim"><?= admin_dt($u['created_at'] ?? null) ?></td>
                <td>
                  <div class="admin-actions">
                    <a class="btn small" href="/admin/users/<?= (int) $u['id'] ?>">View</a>
                    <?php $this->load->view('admin/partials/open_dashboard', ['target' => $u, 'csrfToken' => $csrfToken]); ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <a class="panel-foot-link" href="/admin/users">Open user management →</a>
  </section>
  <section class="panel">
    <h3>Recent admin activity</h3>
    <div class="body">
      <?php $logs = $s['recentAdmin'] ?? []; ?>
      <?php if (!$logs): ?>
        <div class="empty-state" style="padding:20px"><p>No administrator actions recorded yet.</p></div>
      <?php else: ?>
        <div class="feed">
          <?php foreach ($logs as $log): ?>
            <div class="row">
              <span class="t"><?= e($log['admin_label'] ?? '') ?> · <?= e(str_replace('_', ' ', strtolower((string) ($log['action'] ?? '')))) ?></span>
              <span class="d"><?= admin_dt($log['created_at'] ?? null) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php if (admin_can('admin.logs.view')): ?><a class="panel-foot-link" href="/admin/logs">Open activity logs →</a><?php endif; ?>
  </section>
</div>

<?php if (admin_can('admin.users.view')): ?>
<section class="panel" style="margin-top:16px">
  <h3 style="display:flex;align-items:center;justify-content:space-between">
    <span>Recent inbox messages</span>
    <a class="btn small ghost" href="/admin/inbox">Open inbox</a>
  </h3>
  <div class="body table-scroll">
    <?php $recentInbox = $s['recentInbox'] ?? []; ?>
    <?php if (!$recentInbox): ?>
      <div class="empty-state"><p>No contact messages yet. Submissions from the public Contact page will appear here.</p></div>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th></th><th>From</th><th>Subject</th><th>Status</th><th>Received</th></tr></thead>
        <tbody>
          <?php foreach ($recentInbox as $m): ?>
            <tr>
              <td style="width:16px"><?= empty($m['is_read']) ? '<span style="display:inline-block;width:8px;height:8px;border-radius:999px;background:#dc2626"></span>' : '' ?></td>
              <td><a href="/admin/inbox/<?= (int) $m['id'] ?>"><strong><?= e($m['sender_name'] ?? '') ?></strong></a><div class="dim" style="font-size:12px"><?= e($m['sender_email'] ?? '') ?></div></td>
              <td><a href="/admin/inbox/<?= (int) $m['id'] ?>"><?= e($m['subject'] ?? '') ?></a></td>
              <td><span class="badge <?= ((int)($m['is_read']??0) === 0 ? 'b-red' : 'b-gray') ?>"><?= e(($m['status'] ?? 'new')) ?></span></td>
              <td class="dim"><?= admin_dt($m['created_at'] ?? null) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <a class="panel-foot-link" href="/admin/inbox">Go to admin inbox →</a>
</section>
<?php endif; ?>

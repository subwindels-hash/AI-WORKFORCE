<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <h2>Alerts</h2>
    <p><?= (int) $inbox['unread'] ?> unread. Risk transitions, approvals, executions, broker disconnects and kill-switch events — one unread item per active issue.</p>
  </div>
</div>

<div class="panel">
  <h3>Inbox</h3>
  <div class="body scroll" style="padding-top:12px">
    <?php if (empty($inbox['notifications'])): ?>
      <p class="dim">Nothing yet — run a portfolio scan (Risk Center) or create an execution proposal.</p>
    <?php else: ?>
      <div style="margin-bottom:8px">
        <form method="post" action="/notifications/read-all"><button class="btn small">Mark all read</button></form>
      </div>
      <table class="tbl mono">
        <thead><tr><th>At</th><th>Severity</th><th>Type</th><th>Title</th><th>Detail</th><th class="num"></th></tr></thead>
        <tbody>
          <?php foreach ($inbox['notifications'] as $n): ?>
            <?php $fromAdmin = in_array((string) ($n['type'] ?? ''), ['admin_message', 'admin_broadcast'], true);
            $msg = $fromAdmin && isset($n['detail']['message']) ? (string) $n['detail']['message'] : null; ?>
            <tr style="<?= $n['read_at'] ? 'opacity:.55' : 'font-weight:600' ?>">
              <td class="dim"><?= e(substr((string) $n['created_at'], 5, 14)) ?></td>
              <td>
                <?php $tone = ['critical' => 'b-red', 'warning' => 'b-amber', 'info' => 'b-sky'][$n['severity']] ?? 'b-gray'; ?>
                <span class="badge <?= $tone ?>"><?= e($n['severity']) ?></span>
              </td>
              <td><?= $fromAdmin ? '<span class="badge b-violet">Admin</span>' : e($n['type']) ?></td>
              <td><?= e($n['title']) ?><?php if ($fromAdmin && !empty($n['detail']['from'])): ?><br><span class="dim" style="font-weight:400">from <?= e((string) $n['detail']['from']) ?></span><?php endif; ?><?= !$fromAdmin && $n['user_id'] === null ? ' <span class="dim">(broadcast)</span>' : '' ?></td>
              <td class="dim" style="font-weight:400">
                <?php if ($msg !== null): ?>
                  <div style="white-space:pre-wrap;max-width:420px"><?= e($msg) ?></div>
                <?php else: ?>
                  <details><summary style="cursor:pointer">detail</summary><pre style="font-size:10px;white-space:pre-wrap"><?= e(json_encode($n['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></details>
                <?php endif; ?>
              </td>
              <td class="num">
                <?php if ($n['read_at'] === null): ?>
                  <form method="post" action="/notifications/<?= e($n['id']) ?>/read"><button class="btn small">mark read</button></form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

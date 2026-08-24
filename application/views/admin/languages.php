<?php defined('BASEPATH') or exit('No direct script access allowed'); $o = $overview ?? []; ?>
<div class="page-head">
  <div>
    <p class="eyebrow">Platform</p>
    <h2>Language Learning</h2>
    <p>Counts come from stored language profiles, assessments and study sessions.</p>
  </div>
</div>
<div class="grid four">
  <div class="kp-card"><div class="k">Languages</div><div class="v"><?= (int) ($o['languages'] ?? 0) ?></div><div class="trend">Registry entries</div></div>
  <div class="kp-card"><div class="k">Profiles</div><div class="v"><?= (int) ($o['profiles'] ?? 0) ?></div><div class="trend">User language profiles</div></div>
  <div class="kp-card"><div class="k">Study sessions</div><div class="v"><?= (int) ($o['sessions'] ?? 0) ?></div><div class="trend">Recorded activity days</div></div>
  <div class="kp-card"><div class="k">Assessments</div><div class="v"><?= (int) ($o['assessments'] ?? 0) ?></div><div class="trend">Started or completed</div></div>
</div>
<section class="panel" style="margin-top:16px">
  <h3>Language catalog</h3>
  <div class="body table-scroll">
    <?php if (empty($o['catalog'])): ?>
      <div class="empty-state"><p>The language registry is empty.</p></div>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>Code</th><th>Name</th><th>Native</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($o['catalog'] as $lang): ?>
            <tr>
              <td class="mono"><?= e($lang['code'] ?? '') ?></td>
              <td><?= e($lang['name'] ?? '') ?></td>
              <td class="dim"><?= e($lang['native_name'] ?? '') ?></td>
              <td><span class="badge <?= !empty($lang['active']) ? 'b-green' : 'b-gray' ?>"><?= !empty($lang['active']) ? 'Active' : 'Off' ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

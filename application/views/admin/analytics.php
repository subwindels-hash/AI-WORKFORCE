<?php defined('BASEPATH') or exit('No direct script access allowed'); $o = $overview ?? []; $users = $o['users'] ?? []; ?>
<div class="page-head">
  <div>
    <p class="eyebrow">Platform</p>
    <h2>Analytics</h2>
    <p>Every figure is a live count from the database.</p>
  </div>
</div>
<div class="grid four">
  <div class="kp-card"><div class="k">Users</div><div class="v"><?= (int) ($users['total'] ?? 0) ?></div><div class="trend"><?= (int) ($users['active'] ?? 0) ?> active · <?= (int) ($users['suspended'] ?? 0) ?> suspended</div></div>
  <div class="kp-card"><div class="k">AI runs</div><div class="v"><?= (int) ($o['aiRuns'] ?? 0) ?></div><div class="trend">analysis_runs</div></div>
  <div class="kp-card"><div class="k">Language profiles</div><div class="v"><?= (int) ($o['languageProfiles'] ?? 0) ?></div><div class="trend"><?= (int) ($o['studySessions'] ?? 0) ?> study sessions</div></div>
  <div class="kp-card"><div class="k">Conversations</div><div class="v"><?= (int) ($o['conversations'] ?? 0) ?></div><div class="trend">Teacher sessions</div></div>
</div>
<div class="grid four" style="margin-top:16px">
  <div class="kp-card"><div class="k">Successful logins</div><div class="v"><?= (int) ($o['successLogins'] ?? 0) ?></div><div class="trend">auth_events LOGIN_SUCCEEDED</div></div>
  <div class="kp-card"><div class="k">Failed logins</div><div class="v"><?= (int) ($o['failedLogins'] ?? 0) ?></div><div class="trend">auth_events LOGIN_FAILED</div></div>
  <div class="kp-card"><div class="k">Notifications</div><div class="v"><?= (int) ($o['notifications'] ?? 0) ?></div><div class="trend">Stored operator notices</div></div>
</div>

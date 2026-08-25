<?php defined('BASEPATH') or exit('No direct script access allowed');
$o = $overview ?? []; $s = $stats ?? []; $users = $o['users'] ?? [];
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Operations</p>
    <h2>Reports</h2>
    <p>Snapshot of recorded activity. These are database counts, not projections.</p>
  </div>
</div>
<section class="panel">
  <h3>Platform snapshot</h3>
  <div class="body table-scroll">
    <table class="tbl">
      <tbody>
        <tr><td>Total users</td><td class="num"><?= (int) ($users['total'] ?? 0) ?></td></tr>
        <tr><td>Active / suspended</td><td class="num"><?= (int) ($users['active'] ?? 0) ?> / <?= (int) ($users['suspended'] ?? 0) ?></td></tr>
        <tr><td>New users (7 days)</td><td class="num"><?= (int) ($s['newUsers'] ?? 0) ?></td></tr>
        <tr><td>Logins in last 30 days</td><td class="num"><?= (int) ($s['recentLogins'] ?? 0) ?></td></tr>
        <tr><td>AI analysis runs</td><td class="num"><?= (int) ($o['aiRuns'] ?? 0) ?></td></tr>
        <tr><td>Language profiles / study sessions</td><td class="num"><?= (int) ($o['languageProfiles'] ?? 0) ?> / <?= (int) ($o['studySessions'] ?? 0) ?></td></tr>
        <tr><td>Conversations</td><td class="num"><?= (int) ($o['conversations'] ?? 0) ?></td></tr>
        <tr><td>Successful / failed logins</td><td class="num"><?= (int) ($o['successLogins'] ?? 0) ?> / <?= (int) ($o['failedLogins'] ?? 0) ?></td></tr>
      </tbody>
    </table>
  </div>
</section>

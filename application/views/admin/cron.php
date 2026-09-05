<?php defined('BASEPATH') or exit('No direct script access allowed');
$jobs = $jobs ?? [];
$autoRun = !empty($autoRun);
$secret = (string) ($secret ?? '');
$runUrl = (string) ($runUrl ?? '');
$cliCommand = (string) ($cliCommand ?? '');
$recent = $recent ?? [];
$lastTrigger = $lastTrigger ?? null;
if (!function_exists('cron_ago')) {
    function cron_ago(?string $iso): string {
        if ($iso === null || $iso === '') return 'never';
        $at = strtotime($iso);
        if ($at === false) return 'never';
        $s = time() - $at;
        if ($s < 0) return 'in the future';
        if ($s < 90) return $s . 's ago';
        if ($s < 5400) return (int) floor($s / 60) . 'm ago';
        if ($s < 129600) return (int) floor($s / 3600) . 'h ago';
        return (int) floor($s / 86400) . 'd ago';
    }
}
if (!function_exists('cron_in')) {
    function cron_in(?string $iso): string {
        if ($iso === null || $iso === '') return '—';
        $at = strtotime($iso);
        if ($at === false) return '—';
        $s = $at - time();
        if ($s <= 0) return 'due now';
        if ($s < 5400) return 'in ' . max(1, (int) floor($s / 60)) . 'm';
        if ($s < 129600) return 'in ' . (int) floor($s / 3600) . 'h';
        return 'in ' . (int) floor($s / 86400) . 'd';
    }
}
$enabledCount = count(array_filter($jobs, fn($j) => !empty($j['enabled'])));
$dueCount = count(array_filter($jobs, fn($j) => !empty($j['enabled']) && !empty($j['due'])));
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Administration</p>
    <h2>Cron Jobs</h2>
    <p>Every scheduled job in one place. Only enabled + due jobs execute, so the runner is safe to hit every minute — per-job locks prevent overlaps and one failing job never aborts the rest.</p>
  </div>
</div>

<section class="panel">
  <h3>Status</h3>
  <div class="body">
    <div class="stat-grid" style="margin:10px 0">
      <div class="stat"><div class="k">Auto-run on admin visits</div><div class="v"><span class="badge <?= $autoRun ? 'b-green' : 'b-gray' ?>"><?= $autoRun ? 'ON' : 'OFF' ?></span></div></div>
      <div class="stat"><div class="k">Jobs enabled</div><div class="v"><?= $enabledCount ?> / <?= count($jobs) ?></div></div>
      <div class="stat"><div class="k">Due right now</div><div class="v"><?= $dueCount ?></div></div>
      <div class="stat"><div class="k">Last auto-trigger</div><div class="v" style="font-size:13px"><?= $lastTrigger ? e(cron_ago(gmdate('c', (int) $lastTrigger))) : 'never' ?></div></div>
    </div>
  </div>
</section>

<section class="panel" style="margin-top:14px">
  <h3>Schedule &amp; auto-run</h3>
  <div class="body">
    <form method="post" action="/admin/cron/save" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <label class="choice"><input type="checkbox" name="auto_run" value="1" <?= $autoRun ? 'checked' : '' ?>> Auto-run: fire due jobs in the background whenever a super admin visits the dashboard (fallback — a real hosting cron below is more reliable)</label>
      <?php foreach ($jobs as $id => $j): ?>
        <label class="choice"><input type="checkbox" name="enabled_<?= e((string) $id) ?>" value="1" <?= !empty($j['enabled']) ? 'checked' : '' ?>> <b><?= e((string) ($j['label'] ?? $id)) ?></b> <span class="dim">— <?= e((string) ($j['schedule'] ?? '')) ?> · <?= e((string) ($j['description'] ?? '')) ?></span></label>
      <?php endforeach; ?>
      <button class="btn primary" type="submit">Save schedule</button>
    </form>
  </div>
</section>

<section class="panel" style="margin-top:14px">
  <h3>Hosting setup (recommended)</h3>
  <div class="body">
    <p class="dim">Point your hosting cron at <b>one</b> of these every minute. The scheduler runs only the jobs that are enabled + due.</p>
    <label>Secret cron URL (keep private — it runs jobs)<input readonly onclick="this.select()" value="<?= e($runUrl) ?>" style="font-family:monospace;font-size:12px"></label>
    <label>Option A — URL cron (Hostinger / cPanel “cron job” with curl)<input readonly onclick="this.select()" value="<?= e('* * * * * curl -fsS "' . $runUrl . '" >/dev/null 2>&1') ?>" style="font-family:monospace;font-size:12px"></label>
    <label>Option B — command cron (VPS / SSH)<input readonly onclick="this.select()" value="<?= e('* * * * * ' . $cliCommand . ' >> /var/log/ai_workforce-cron.log 2>&1') ?>" style="font-family:monospace;font-size:12px"></label>
    <form method="post" action="/admin/cron/secret" onsubmit="return confirm('Generate a new secret? Your existing hosting cron command will stop working until you update it.')" style="margin-top:8px">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <button class="btn" type="submit">Generate new secret</button>
    </form>
  </div>
</section>

<section class="panel" style="margin-top:14px">
  <h3>Jobs</h3>
  <div class="body">
    <table class="tbl">
      <thead><tr><th>Job</th><th>Schedule</th><th>Enabled</th><th>Last run</th><th>Next due</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($jobs as $id => $j): $last = is_array($j['lastRun'] ?? null) ? $j['lastRun'] : null; $st = (string) ($last['status'] ?? 'NEVER'); ?>
          <tr>
            <td><b><?= e((string) ($j['label'] ?? $id)) ?></b><br><span class="dim" style="font-size:11px"><?= e((string) ($j['group'] ?? '')) ?></span></td>
            <td><?= e((string) ($j['schedule'] ?? '')) ?></td>
            <td><span class="badge <?= !empty($j['enabled']) ? 'b-green' : 'b-gray' ?>"><?= !empty($j['enabled']) ? 'ON' : 'OFF' ?></span></td>
            <td>
              <?php if ($last): ?>
                <span class="badge <?= $st === 'FAILED' ? 'b-red' : ($st === 'COMPLETED_WITH_ERRORS' ? 'b-amber' : 'b-green') ?>"><?= e($st) ?></span>
                <span class="dim" style="font-size:11px"><?= e(cron_ago($last['at'] ?? null)) ?> · <?= number_format((int) ($last['durationMs'] ?? 0) / 1000, 1) ?>s</span>
                <?php if (!empty($last['error'])): ?><br><span class="dim" style="font-size:11px"><?= e(mb_substr((string) $last['error'], 0, 160)) ?></span>
                <?php elseif (!empty($last['summary'])): ?><br><span class="dim" style="font-size:11px"><?= e(mb_substr((string) $last['summary'], 0, 160)) ?></span><?php endif; ?>
              <?php else: ?>
                <span class="dim">never ran</span>
              <?php endif; ?>
            </td>
            <td class="dim" style="font-size:12px"><?= !empty($j['enabled']) ? e(cron_in($j['nextDueAt'] ?? null)) : '—' ?></td>
            <td>
              <form method="post" action="/admin/cron/run/<?= e((string) $id) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <button class="btn small" type="submit">Run now</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if (!empty($recent)): ?>
<section class="panel" style="margin-top:14px">
  <h3>Recent cron activity</h3>
  <div class="body">
    <table class="tbl">
      <thead><tr><th>When</th><th>Event</th><th>Summary</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $r): ?>
          <tr>
            <td class="mono dim" style="white-space:nowrap"><?= e(substr((string) ($r['at'] ?? ''), 0, 16)) ?></td>
            <td class="mono" style="font-size:12px"><?= e((string) ($r['type'] ?? '')) ?></td>
            <td class="dim" style="font-size:12px"><?= e(mb_substr((string) ($r['summary'] ?? ''), 0, 200)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php defined('BASEPATH') or exit('No direct script access allowed');
$plan = is_array($plan ?? null) ? $plan : [];
$lines = is_array($lines ?? null) ? $lines : [];
$mains = is_array($mains ?? null) ? $mains : [];
$stars = is_array($stars ?? null) ? $stars : [];
?>
<div class="page-head">
  <div>
    <h2>EuroMillions · System (wheel) builder</h2>
    <p>Every valid C(N,5) × C(S,2) line from your pool. Line counts are computed, never hardcoded. Cost is shown only when an official price is known.</p>
  </div>
  <a class="btn" href="/lottery">← Lottery Intelligence</a>
</div>
<form method="get" action="/api/lottery/system" class="panel" style="padding:14px;margin:12px 0">
  <label>Main pool (comma-separated, ≥5 numbers 1–50)
    <input name="mains" value="<?= e(implode(',', $mains)) ?>" style="width:100%;margin:6px 0 12px">
  </label>
  <label>Star pool (comma-separated, ≥2 numbers 1–12)
    <input name="stars" value="<?= e(implode(',', $stars)) ?>" style="width:100%;margin:6px 0 12px">
  </label>
  <button class="btn primary" type="submit">Build wheel</button>
</form>
<?php if ($plan !== []): ?>
  <div class="lottery-card" style="border:1px solid var(--line);border-radius:var(--radius);padding:14px;background:var(--panel)">
    <h3><?= e((string) ($plan['formula'] ?? '')) ?></h3>
    <p><strong><?= (int) ($plan['totalLines'] ?? 0) ?></strong> lines
      · mains <?= (int) ($plan['mainCombos'] ?? 0) ?>
      · stars <?= (int) ($plan['starCombos'] ?? 0) ?></p>
    <p class="dim"><?= e((string) ($plan['disclaimer'] ?? \AIWorkforce\Lottery\LotteryStatisticsEngine::DISCLAIMER)) ?></p>
    <p class="dim"><?= e((string) ($plan['costNote'] ?? 'Official ticket cost is not available — no cost is fabricated.')) ?></p>
    <?php if (!empty($plan['requiresBackground'])): ?>
      <p class="notice warnbox">This wheel is above the synchronous limit. Queue it with POST /api/lottery/system-build (lottery.manage).</p>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php if ($lines !== []): ?>
  <table class="tbl mono">
    <thead><tr><th>#</th><th>Mains</th><th>Stars</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $i => $line): ?>
      <tr>
        <td><?= (int) (($page ?? 0) * ($limit ?? 50) + $i + 1) ?></td>
        <td><?php foreach (($line['mains'] ?? []) as $n): ?><span class="ball" style="display:inline-flex;width:28px;height:28px;border-radius:50%;background:#ffd24a;color:#1b1b1b;align-items:center;justify-content:center;margin-right:3px;font-weight:700"><?= (int)$n ?></span><?php endforeach; ?></td>
        <td><?php foreach (($line['stars'] ?? []) as $n): ?><span class="lucky-star" style="display:inline-flex;width:28px;height:28px;border-radius:50%;background:#7dd3fc;color:#0c2e46;align-items:center;justify-content:center;margin-right:3px;font-weight:700"><?= (int)$n ?></span><?php endforeach; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

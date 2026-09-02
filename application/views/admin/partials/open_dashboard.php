<?php
defined('BASEPATH') or exit('No direct script access allowed');
$target = is_array($target ?? null) ? $target : [];
if (!function_exists('admin_can_open_dashboard') || !admin_can_open_dashboard($target)) return;
$id = (int) ($target['id'] ?? 0);
if ($id <= 0) return;
$label = (string) ($label ?? 'Open dashboard');
$class = (string) ($class ?? 'btn small');
?>
<form method="post" action="/admin/users/<?= $id ?>/impersonate" onsubmit="return confirm('Open this account dashboard? You will see it as that user. The action is recorded in the activity log.');">
  <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
  <button class="<?= e($class) ?>" type="submit"><?= e($label) ?></button>
</form>

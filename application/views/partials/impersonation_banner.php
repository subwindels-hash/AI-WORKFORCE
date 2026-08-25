<?php
defined('BASEPATH') or exit('No direct script access allowed');
$ci = get_instance();
$admin = $ci->session->userdata('impersonator');
if (!is_array($admin) || empty($admin['id'])) return;
if (!function_exists('e')) {
    function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
?>
<div class="impersonation-banner" role="status">
  <span>⚠️ You are currently viewing this account as an administrator.</span>
  <form method="post" action="/admin/impersonation/return">
    <input type="hidden" name="csrf_token" value="<?= e((string) $ci->session->userdata('csrf_token')) ?>">
    <button class="btn small" type="submit">Return to Admin Account</button>
  </form>
</div>

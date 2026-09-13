<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * In-page sign-in prompt for read-only console pages viewed while logged out.
 *
 * The page shell (global header, sidebar, page title) is already rendered
 * around this partial, so the visitor sees the correct destination and a clear
 * call to sign in — instead of being bounced straight to /login. `$signInUrl`
 * carries a same-origin return_to so authentication lands back on this page.
 *
 * @var string $signInUrl  Absolute /login?return_to=… URL (required)
 * @var string $gateTitle  Optional heading override
 * @var string $gateBody   Optional supporting copy override
 */
$signInUrl = isset($signInUrl) && is_string($signInUrl) && $signInUrl !== '' ? $signInUrl : '/login';
$gateTitle = isset($gateTitle) && is_string($gateTitle) && $gateTitle !== '' ? $gateTitle : 'Sign in to view this page';
$gateBody  = isset($gateBody) && is_string($gateBody) && $gateBody !== ''
    ? $gateBody
    : 'This console reads members\' stored intelligence data, so it is available to signed-in accounts only. Sign in to continue and you will be returned to this page.';
?>
<section class="panel signin-gate" role="region" aria-labelledby="signin-gate-heading">
  <div class="signin-gate__inner">
    <span class="signin-gate__icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" width="40" height="40"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 15v2"/></svg>
    </span>
    <h2 id="signin-gate-heading"><?= e($gateTitle) ?></h2>
    <p><?= e($gateBody) ?></p>
    <div class="signin-gate__actions">
      <a class="btn primary" href="<?= e($signInUrl) ?>">Sign in</a>
      <a class="btn ghost" href="/register">Create an account</a>
    </div>
    <p class="signin-gate__note">No fabricated data is ever shown here. Figures appear only when they can be traced to stored, verified sources.</p>
  </div>
</section>

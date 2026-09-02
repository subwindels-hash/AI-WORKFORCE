<?php
defined('BASEPATH') or exit('No direct script access allowed');
if (!function_exists('e')) {
    function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}
$title = $title ?? 'Create an account';
$active = 'register';
$bodyClass = 'auth-page--wide';
$this->load->view('auth/layout/header', ['title' => $title, 'active' => $active, 'bodyClass' => $bodyClass]);
?>
<main class="auth-shell auth-shell--register">
  <div class="auth-reg">

    <!-- Page heading -->
    <header class="auth-reg-head">
      <p class="eyebrow">Create your account</p>
      <h1>Start your WINDELS AI Workforce workspace</h1>
      <p class="auth-reg-lead">
        Register once and get a unique six-digit User&nbsp;ID and a 4-digit Security PIN, both assigned
        automatically. Sign in later with your username, email or User&nbsp;ID.
      </p>
    </header>

    <?php if (!empty($error)): ?><div class="notice err auth-notice" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if (!empty($notice)): ?><div class="notice ok auth-notice" role="status"><?= e($notice) ?></div><?php endif; ?>

    <div class="auth-reg-grid">

      <!-- LEFT — organised registration form -->
      <section class="auth-card auth-card--form">
        <form method="post" action="/register/submit" class="auth-form auth-form--sections" id="register-form" autocomplete="on" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e((string) ($csrfToken ?? '')) ?>">

          <!-- Step 1 — Account -->
          <fieldset class="auth-section">
            <legend><span class="auth-step">1</span> Account details</legend>
            <p class="auth-section-note">How you will be identified inside the platform.</p>
            <div class="auth-cols">
              <label class="auth-field">
                <span>Username</span>
                <span class="auth-control">
                  <input name="username" id="reg-username" required maxlength="20" autocomplete="username" placeholder="carlosjohn" autofocus>
                </span>
                <span class="auth-hint">3–20 characters, letters, numbers or underscores, starting with a letter.</span>
              </label>

              <label class="auth-field">
                <span>Email address</span>
                <span class="auth-control">
                  <input type="email" name="email" id="reg-email" required maxlength="190" autocomplete="email" placeholder="you@example.com" inputmode="email">
                </span>
                <span class="auth-hint">Used for sign-in and account notices.</span>
              </label>
            </div>
          </fieldset>

          <!-- Step 2 — Contact -->
          <fieldset class="auth-section">
            <legend><span class="auth-step">2</span> Contact details</legend>
            <p class="auth-section-note">Carried into the contact page when you are signed in.</p>
            <div class="auth-cols">
              <label class="auth-field">
                <span>Phone number</span>
                <span class="auth-control">
                  <input type="tel" name="phone" id="reg-phone" required maxlength="40" autocomplete="tel" inputmode="tel" placeholder="+234 800 000 0000">
                </span>
                <span class="auth-hint">Include the country code.</span>
              </label>

              <label class="auth-field">
                <span>Address</span>
                <span class="auth-control">
                  <textarea name="address" id="reg-address" required minlength="5" maxlength="255" rows="2" autocomplete="street-address" placeholder="Street, city, country"></textarea>
                </span>
                <span class="auth-hint">At least 5 characters.</span>
              </label>
            </div>
          </fieldset>

          <!-- Step 3 — Password -->
          <fieldset class="auth-section">
            <legend><span class="auth-step">3</span> Password</legend>
            <p class="auth-section-note">Use at least 12 characters. Both entries must match.</p>
            <div class="auth-cols">
              <label class="auth-field">
                <span>Password</span>
                <span class="auth-control has-toggle">
                  <input type="password" name="password" id="reg-password" required minlength="12" autocomplete="new-password" placeholder="At least 12 characters">
                  <button type="button" class="pw-toggle" data-toggle="reg-password" aria-label="Show password" title="Show password">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/></svg>
                  </button>
                </span>
                <span class="auth-hint" id="reg-password-strength">Minimum 12 characters.</span>
              </label>

              <label class="auth-field">
                <span>Confirm password</span>
                <span class="auth-control has-toggle">
                  <input type="password" name="password_confirm" id="reg-confirm" required minlength="12" autocomplete="new-password" placeholder="Repeat your password">
                  <button type="button" class="pw-toggle" data-toggle="reg-confirm" aria-label="Show password" title="Show password">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/></svg>
                  </button>
                </span>
                <span class="auth-hint" id="reg-confirm-hint">Repeat the same password.</span>
              </label>
            </div>
          </fieldset>

          <!-- Step 4 — Account recovery -->
          <fieldset class="auth-section">
            <legend><span class="auth-step">4</span> Account recovery</legend>
            <p class="auth-section-note">Used to verify you if you ever lose access to your account.</p>
            <div class="auth-cols">
              <label class="auth-field">
                <span>Security question</span>
                <span class="auth-control">
                  <select name="security_question" id="reg-question" required>
                    <option value="">Choose a question</option>
                    <?php foreach (($securityQuestions ?? []) as $q): ?>
                      <option value="<?= e($q) ?>"><?= e($q) ?></option>
                    <?php endforeach; ?>
                    <option value="__custom__">Write your own question</option>
                  </select>
                </span>
              </label>

              <label class="auth-field">
                <span>Security answer</span>
                <span class="auth-control">
                  <input name="security_answer" id="reg-answer" required maxlength="120" minlength="2" autocomplete="off" placeholder="Your answer">
                </span>
                <span class="auth-hint">At least 2 characters.</span>
              </label>
            </div>

            <label class="auth-field auth-field--full" id="reg-question-custom-wrap" hidden>
              <span>Your security question</span>
              <span class="auth-control">
                <input name="security_question_custom" id="reg-question-custom" maxlength="255" minlength="8" placeholder="At least 8 characters">
              </span>
            </label>
          </fieldset>

          <!-- Step 5 — Confirm and submit -->
          <fieldset class="auth-section auth-section--submit">
            <legend><span class="auth-step">5</span> Confirm and finish</legend>
            <label class="auth-check">
              <input type="checkbox" name="terms" id="reg-terms" value="1" required>
              <span>I agree to the <a href="/safety">Terms</a> and <a href="/safety">Privacy Policy</a>.</span>
            </label>

            <div id="register-inline-error" class="notice err" role="alert" hidden></div>

            <button class="btn primary auth-submit" type="submit" id="register-submit">Create account</button>
            <p class="auth-submit-note">Super Admin can read the security question and answer from the dashboard.</p>
          </fieldset>
        </form>

        <div class="auth-foot">
          Already have an account? <a href="/login"><b>Sign in</b></a>
        </div>
      </section>

      <!-- RIGHT — supporting summary -->
      <aside class="auth-aside">
        <div class="auth-visual-frame">
          <img src="/assets/images/hero-africa-mobility.jpg" alt="WINDELS AI WORKFORCE — create your workspace" class="auth-visual-img" loading="lazy"
               onerror="this.style.display='none';this.nextElementSibling.style.display='grid';">
          <div class="auth-visual-fallback" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h13a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H3z"/><path d="m3 4 1 7 1-7M19 5l3 2-3 2"/></svg></div>
        </div>

        <div class="auth-aside-card">
          <h2>What you get</h2>
          <ul class="auth-benefits">
            <li><strong>Unique User ID</strong> — a six-digit ID assigned automatically.</li>
            <li><strong>Security PIN</strong> — a 4-digit PIN generated for your account.</li>
            <li><strong>Full AI workforce</strong> — language teacher, market analysis, sports &amp; lottery research and lead discovery.</li>
            <li><strong>Evidence-first</strong> — audited, fail-closed, and synthetic data always labelled.</li>
          </ul>
        </div>

        <div class="auth-aside-card auth-aside-card--muted">
          <h2>Need help?</h2>
          <p>Registration takes under a minute. If something goes wrong, our team can help.</p>
          <p><a href="/contact">Contact support</a> · <a href="/faq">Read the FAQ</a></p>
        </div>
      </aside>
    </div>
  </div>
</main>

<script>
(function () {
  'use strict';
  var form = document.getElementById('register-form');
  var submit = document.getElementById('register-submit');
  var inlineError = document.getElementById('register-inline-error');

  document.querySelectorAll('.pw-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-toggle'));
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.title = show ? 'Hide password' : 'Show password';
    });
  });

  var question = document.getElementById('reg-question');
  var customWrap = document.getElementById('reg-question-custom-wrap');
  var customInput = document.getElementById('reg-question-custom');
  function syncCustomQuestion() {
    var custom = question.value === '__custom__';
    customWrap.hidden = !custom;
    customInput.required = custom;
  }
  question.addEventListener('change', syncCustomQuestion);
  syncCustomQuestion();

  // Live feedback for the password section.
  var password = document.getElementById('reg-password');
  var confirmInput = document.getElementById('reg-confirm');
  var strength = document.getElementById('reg-password-strength');
  var confirmHint = document.getElementById('reg-confirm-hint');
  function syncPassword() {
    var v = password.value || '';
    if (!v) { strength.textContent = 'Minimum 12 characters.'; strength.className = 'auth-hint'; }
    else if (v.length < 12) { strength.textContent = 'Too short — ' + (12 - v.length) + ' more character(s) needed.'; strength.className = 'auth-hint is-warn'; }
    else { strength.textContent = 'Password length looks good.'; strength.className = 'auth-hint is-ok'; }
    if (!confirmInput.value) { confirmHint.textContent = 'Repeat the same password.'; confirmHint.className = 'auth-hint'; }
    else if (confirmInput.value !== v) { confirmHint.textContent = 'The two passwords do not match.'; confirmHint.className = 'auth-hint is-warn'; }
    else { confirmHint.textContent = 'Passwords match.'; confirmHint.className = 'auth-hint is-ok'; }
  }
  password.addEventListener('input', syncPassword);
  confirmInput.addEventListener('input', syncPassword);

  function fail(message, focusEl) {
    inlineError.hidden = false;
    inlineError.textContent = message;
    if (focusEl) focusEl.focus();
    return false;
  }

  form.addEventListener('submit', function (event) {
    var username = document.getElementById('reg-username');
    var email = document.getElementById('reg-email');
    var phone = document.getElementById('reg-phone');
    var address = document.getElementById('reg-address');
    var q = document.getElementById('reg-question');
    var customQ = document.getElementById('reg-question-custom');
    var answer = document.getElementById('reg-answer');
    var terms = document.getElementById('reg-terms');
    inlineError.hidden = true;
    var ok = true;
    var phoneDigits = (phone.value || '').replace(/\D/g, '');
    if (!/^[a-z][a-z0-9_]{2,19}$/i.test(username.value.trim())) ok = fail('Username must be 3–20 characters, start with a letter, and use only letters, numbers or underscores.', username);
    else if (!email.value.trim()) ok = fail('Enter your email address.', email);
    else if (phoneDigits.length < 7 || phoneDigits.length > 15) ok = fail('Enter a valid phone number with country code.', phone);
    else if ((address.value || '').trim().length < 5) ok = fail('Enter your street address.', address);
    else if (password.value.length < 12) ok = fail('Your password must be at least 12 characters.', password);
    else if (password.value !== confirmInput.value) ok = fail('The two passwords do not match.', confirmInput);
    else if (!q.value) ok = fail('Choose a security question.', q);
    else if (q.value === '__custom__' && (customQ.value || '').trim().length < 8) ok = fail('Write a security question of at least 8 characters.', customQ);
    else if ((answer.value || '').trim().length < 2) ok = fail('Enter an answer of at least 2 characters.', answer);
    else if (!terms.checked) ok = fail('Please accept the Terms and Privacy Policy.', terms);
    if (!ok) { event.preventDefault(); return; }
    submit.disabled = true;
    submit.classList.add('is-loading');
    submit.innerHTML = '<span class="auth-spinner"></span> Creating account…';
  });
})();
</script>
<?php $this->load->view('auth/layout/footer'); ?>

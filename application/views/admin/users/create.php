<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <p class="eyebrow">Directory</p>
    <h2>Create user</h2>
    <p>A unique six-digit User ID and a 4-digit Security PIN are assigned automatically. The password is hashed immediately and is not shown again.</p>
  </div>
  <a class="btn" href="/admin/users">Cancel</a>
</div>
<section class="panel">
  <div class="body">
    <form method="post" action="/admin/users/create" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <label>Username<input name="username" required maxlength="20" pattern="[a-z][a-z0-9_]{2,19}" placeholder="jane_doe"></label>
      <label>Email<input type="email" name="email" required maxlength="190" placeholder="person@example.com"></label>
      <label>Temporary password<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
      <label>Security question
        <select name="security_question" id="create-question">
          <option value="">Optional — choose a question</option>
          <?php foreach (($securityQuestions ?? []) as $q): ?>
            <option value="<?= e($q) ?>"><?= e($q) ?></option>
          <?php endforeach; ?>
          <option value="__custom__">Write your own question</option>
        </select>
      </label>
      <label id="create-question-custom-wrap" hidden>Your security question<input name="security_question_custom" id="create-question-custom" maxlength="255" minlength="8"></label>
      <label>Security answer<input name="security_answer" maxlength="120" minlength="2" placeholder="Optional" autocomplete="off"></label>
      <label>Role
        <select name="role">
          <?php foreach ($roles as $role): ?>
            <option value="<?= e($role['code']) ?>"><?= e($role['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn primary" type="submit">Create account</button>
    </form>
  </div>
</section>
<script>
(function () {
  var q = document.getElementById('create-question');
  var wrap = document.getElementById('create-question-custom-wrap');
  if (!q || !wrap) return;
  function sync() { wrap.hidden = q.value !== '__custom__'; }
  q.addEventListener('change', sync);
  sync();
})();
</script>

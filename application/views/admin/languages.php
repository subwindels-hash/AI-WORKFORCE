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

<!-- Add Language Form (Super Admin only) -->
<section class="panel" style="margin-top:24px">
  <h3>Add New Language</h3>
  <div class="body">
    <p class="dim">Register a new language in the language learning catalog. The language code must be 2-3 lowercase letters (ISO 639-1/3 format). After registering, the language will be available immediately for learning. Assessment and lesson content banks must be authored separately.</p>
    <form method="post" action="/admin/languages/add" class="grid three" style="gap:12px;margin-top:12px">
      <div>
        <label class="fld">Language Code <span class="err">*</span>
        <input class="sel" type="text" name="code" maxlength="3" pattern="[a-z]{2,3}" required 
               placeholder="e.g. pt, ar, hi" style="text-transform:lowercase"></label>
      </div>
      <div>
        <label class="fld">English Name <span class="err">*</span>
        <input class="sel" type="text" name="name" required placeholder="English name"></label>
      </div>
      <div>
        <label class="fld">Native Name
        <input class="sel" type="text" name="native_name" placeholder="Native language name (optional)"></label>
      </div>
      <div>
        <label class="fld">Writing System <span class="err">*</span>
        <select class="sel" name="writing_system" required>
          <option value="latin">Latin</option>
          <option value="cyrillic">Cyrillic</option>
          <option value="devanagari">Devanagari</option>
          <option value="arabic">Arabic</option>
          <option value="han">Han/Chinese Characters</option>
          <option value="kana">Kana</option>
          <option value="hangul">Hangul</option>
        </select>
      </div>
      <div>
        <label class="fld">Direction <span class="err">*</span>
        <select class="sel" name="direction" required>
          <option value="ltr">Left-to-Right (LTR)</option>
          <option value="rtl">Right-to-Left (RTL)</option>
        </select>
      </div>
      <div>
        <label class="fld">&nbsp;
        <button class="btn primary" type="submit">Register Language</button>
        <a class="btn" href="/admin/languages">Cancel</a>
        </label>
      </div>
    </form>
    <p class="dim" style="margin-top:12px">After registering, the language will be available immediately for learning. Assessment and lesson content banks must be authored separately.</p>
  </div>
</section>
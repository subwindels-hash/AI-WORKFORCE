<?php
defined('BASEPATH') or exit('No direct script access allowed');
$contact = $contact ?? [];
$contactEmail = (string) ($contact['email'] ?? 'noreply@yourdomain.com');
$contactPhone = (string) ($contact['phone'] ?? '');
$contactAddress = (string) ($contact['address'] ?? '');
$contactCity = (string) ($contact['city'] ?? 'Abuja, Nigeria');
$mapSrc = (string) ($contact['mapSrc'] ?? '');
$mapLink = (string) ($contact['mapLink'] ?? '');
$mailEnabled = !empty($contact['mailEnabled']);
?>
<section class="page-hero">
  <p class="kicker">Contact</p>
  <h1>Get in touch with the team</h1>
  <p class="lede">Send a message and a member of the WINDELS AI WORKFORCE team will get back to you — usually within one business day.</p>
</section>

<section class="band">
  <div class="contact-grid">
    <div class="contact-side">
      <figure class="contact-media">
        <img src="/assets/images/contact-support.jpg" alt="WINDELS AI WORKFORCE support team" loading="lazy" width="800" height="550">
      </figure>
      <h2>Reach us directly</h2>
      <p class="contact-desc">Questions about the platform, your account or a service? Use the form or any of the contact details.</p>
      <div class="contact-details">
        <div class="contact-detail">
          <span class="contact-label">Email</span>
          <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a>
        </div>
        <?php if ($contactPhone !== ''): ?>
          <div class="contact-detail">
            <span class="contact-label">Phone</span>
            <a href="tel:<?= e(preg_replace('/[^0-9+\-() ]/', '', $contactPhone)) ?>"><?= e($contactPhone) ?></a>
          </div>
        <?php endif; ?>
        <?php if ($contactAddress !== ''): ?>
          <div class="contact-detail">
            <span class="contact-label">Office</span>
            <span><?= e($contactAddress) ?></span>
          </div>
        <?php endif; ?>
        <div class="contact-detail">
          <span class="contact-label">Location</span>
          <span><?= e($contactCity) ?></span>
        </div>
      </div>
    </div>

    <div class="contact-main">
      <?php if (!empty($notice)): ?><div class="flash ok"><?= e($notice) ?></div><?php endif; ?>
      <?php if (!empty($error)): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
      <form class="contact-form" method="post" action="/contact/submit">
        <label>Name<input name="name" required maxlength="120"></label>
        <label>Email<input type="email" name="email" required maxlength="190"></label>
        <label>Message<textarea name="message" required minlength="10" rows="8" maxlength="2000"></textarea></label>
        <button class="btn solid" type="submit">Send message</button>
      </form>
      <?php if (!$mailEnabled): ?>
        <p class="contact-hint">Outbound email is currently disabled on this server. Set <code>VP_SMTP_ENABLED=1</code> and the cPanel SMTP details in <code>.env</code> so submissions are emailed to the operator, and so users receive a confirmation.</p>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($mapSrc !== ''): ?>
<section class="band alt">
  <div class="section-head">
    <p class="kicker">Location</p>
    <h2>Where to find us</h2>
    <p>Our address on the map. Select &ldquo;view larger map&rdquo; for directions or to zoom.</p>
  </div>
  <div class="map-frame">
    <iframe
      title="Map showing <?= e($contactCity) ?>"
      src="<?= e($mapSrc) ?>"
      loading="lazy"
      referrerpolicy="no-referrer-when-downgrade"
      allowfullscreen></iframe>
  </div>
  <p class="map-actions">
    <a class="btn ghost" href="<?= e($mapLink) ?>" target="_blank" rel="noopener">View larger map</a>
  </p>
</section>
<?php endif; ?>

<?php defined('BASEPATH') or exit('No direct script access allowed');
$reviewCount = (int) ($reviewSummary['count'] ?? 0);
$average = (float) ($reviewSummary['average'] ?? 0);
?>
<section class="page-hero reviews-hero">
  <p class="kicker">Community reviews</p>
  <h1>See what users are saying</h1>
  <p class="lede">Read honest comments from people using WINDELS AI WORKFORCE, then share your own experience with the community.</p>
  <div class="review-summary" aria-label="Review summary">
    <strong><?= $reviewCount ? number_format($average, 1) : 'New' ?></strong>
    <div>
      <div class="review-stars" aria-label="<?= $reviewCount ? e(number_format($average, 1) . ' out of 5 stars') : 'No ratings yet' ?>"><?= $reviewCount ? str_repeat('★', (int) round($average)) . str_repeat('☆', 5 - (int) round($average)) : '☆☆☆☆☆' ?></div>
      <span><?= $reviewCount ?> <?= $reviewCount === 1 ? 'community comment' : 'community comments' ?></span>
    </div>
  </div>
</section>

<section class="band reviews-layout">
  <aside class="review-compose">
    <p class="kicker">Join the conversation</p>
    <h2>Share your experience</h2>
    <?php if (!empty($notice)): ?><div class="flash ok" role="status"><?= e($notice) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="flash err" role="alert"><?= e($error) ?></div><?php endif; ?>

    <?php if ($user): ?>
      <p class="review-signed-in">Commenting as <strong><?= e((string) ($user['display_name'] ?? $user['username'] ?? 'Member')) ?></strong></p>
      <form class="review-form" method="post" action="/reviews/submit">
        <input type="hidden" name="csrf_token" value="<?= e((string) get_instance()->session->userdata('csrf_token')) ?>">
        <fieldset>
          <legend>Your rating</legend>
          <div class="rating-input">
            <?php for ($star = 5; $star >= 1; $star--): ?>
              <input type="radio" id="rating-<?= $star ?>" name="rating" value="<?= $star ?>" <?= $star === 5 ? 'required' : '' ?>>
              <label for="rating-<?= $star ?>" title="<?= $star ?> out of 5"><?= $star ?> star<?= $star === 1 ? '' : 's' ?></label>
            <?php endfor; ?>
          </div>
        </fieldset>
        <label for="review-body">Your comment <span>20–1,500 characters</span></label>
        <textarea id="review-body" name="body" rows="7" minlength="20" maxlength="1500" required placeholder="What did you use? What worked well? What should a new user know?"></textarea>
        <button class="btn solid" type="submit">Post comment</button>
        <p class="form-note">Your display name and comment will be public. Never include passwords, account numbers, financial details or other private information.</p>
      </form>
    <?php else: ?>
      <div class="review-login-card">
        <h3>Have something to share?</h3>
        <p>Sign in to post a review. Everyone can read the conversation.</p>
        <a class="btn solid" href="/login?return_to=%2Freviews">Sign in to comment</a>
        <a class="btn ghost" href="/register">Create an account</a>
      </div>
    <?php endif; ?>
  </aside>

  <div class="review-feed">
    <div class="review-feed-head">
      <div><p class="kicker">Latest comments</p><h2>What is happening in the community</h2></div>
      <span><?= $reviewCount ?> total</span>
    </div>
    <?php if (empty($reviews)): ?>
      <div class="review-empty">
        <span aria-hidden="true">✦</span>
        <h3>Start the conversation</h3>
        <p>There are no comments yet. Be the first user to share a helpful, honest review.</p>
      </div>
    <?php else: ?>
      <div class="review-list">
        <?php foreach ($reviews as $review):
          $name = trim((string) ($review['display_name'] ?? $review['username'] ?? 'Member')) ?: 'Member';
          $initial = mb_strtoupper(mb_substr($name, 0, 1));
          $created = strtotime((string) ($review['created_at'] ?? ''));
        ?>
          <article class="review-item">
            <header>
              <span class="review-avatar review-avatar-fallback" aria-hidden="true">
                <?= e($initial) ?>
                <?php if (!empty($review['profile_image'])): ?>
                  <img src="<?= e((string) $review['profile_image']) ?>" alt="" loading="lazy" onerror="this.remove()">
                <?php endif; ?>
              </span>
              <div class="review-author"><strong><?= e($name) ?></strong><span>Verified platform member</span></div>
              <time datetime="<?= e((string) ($review['created_at'] ?? '')) ?>"><?= $created ? e(date('M j, Y', $created)) : '' ?></time>
            </header>
            <div class="review-stars small" aria-label="<?= (int) $review['rating'] ?> out of 5 stars"><?= str_repeat('★', (int) $review['rating']) . str_repeat('☆', 5 - (int) $review['rating']) ?></div>
            <p><?= nl2br(e((string) $review['body'])) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="cta reviews-cta">
  <div><h2>New to WINDELS?</h2><p>Use these community comments to understand the experience, then explore the product for yourself.</p></div>
  <div class="hero-cta"><a class="btn solid" href="/register">Get started</a><a class="btn ghost" href="/services">Explore services</a></div>
</section>

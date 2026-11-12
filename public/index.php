<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

$currency = config('CURRENCY_NAME');
page_start('Earn rewards for completed offers');
?>
<section class="hero">
  <h1>Earn <?= e($currency) ?> for completed offers</h1>
  <p>Try apps and games, answer surveys, or sign up for services. When the advertiser confirms an offer, the <?= e($currency) ?> land in your balance.
    Redeem them once you reach <?= e(format_amount((int)config('REDEEM_MIN_COINS') * MICRO)) ?>.</p>
  <?php if (current_user()): ?>
    <a class="button" href="/earn.php">Go to offers</a>
  <?php else: ?>
    <a class="button" href="/register.php">Create a free account</a> <a href="/login.php">or log in</a>
  <?php endif ?>
</section>
<section class="steps">
  <div class="card"><h2>1. Pick an offer</h2><p>Offers depend on your country and device. Each one shows what to do and what you get.</p></div>
  <div class="card"><h2>2. Complete it</h2><p>Follow the steps exactly, from a normal home or mobile connection. VPNs and proxies are blocked.</p></div>
  <div class="card"><h2>3. Redeem</h2><p>Every request is reviewed by hand. New rewards stay on hold for <?= e(config('REDEEM_HOLD_DAYS')) ?> days.</p></div>
</section>
<p class="muted">You must be 16 or older. One account per person.</p>
<?php
page_end();

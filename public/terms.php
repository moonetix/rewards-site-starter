<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

$site = config('SITE_NAME');
$currency = config('CURRENCY_NAME');
page_start('Terms of service');
?>
<article class="legal">
  <h1>Terms of service</h1>
  <p class="draft">Placeholder. Replace this page with terms written for your business and checked by a lawyer in your country before launch.</p>

  <h2>Who can use <?= e($site) ?></h2>
  <p>You must be 16 or older. If you are under 18, you need a parent's or guardian's permission. One account per person.
    Accounts created to get around this rule are closed and their <?= e($currency) ?> are removed.</p>

  <h2>How you earn</h2>
  <p>You earn <?= e($currency) ?> only for offers you complete as described, confirmed by the advertiser.
    Offers come from third-party advertisers through our offerwall partner. We do not pay for sign-ups, invites, reviews, likes or other engagement.</p>

  <h2>Reversals</h2>
  <p>An advertiser can reverse a confirmed offer later, for example after a refund or when the requirements were not met.
    We then take the reward back from your balance, even if this makes it negative.</p>

  <h2>Redeeming</h2>
  <p>The minimum is <?= e(format_amount((int)config('REDEEM_MIN_COINS') * MICRO)) ?> <?= e($currency) ?>.
    New rewards are on hold for <?= e(config('REDEEM_HOLD_DAYS')) ?> days. We review every request by hand and can ask you to verify your identity.
    We can refuse a request linked to fraud, VPNs, proxies or multiple accounts.</p>

  <h2>Changes and contact</h2>
  <p>Add how you announce changes to these terms, and how users can reach you.</p>
</article>
<?php
page_end();

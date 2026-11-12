<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

$site = config('SITE_NAME');
page_start('Privacy policy');
?>
<article class="legal">
  <h1>Privacy policy</h1>
  <p class="draft">Placeholder. Replace this page with a policy that matches what your site really collects and the law that applies to your users,
    for example the GDPR in the EU.</p>

  <h2>What we store</h2>
  <ul>
    <li>Your email address and a hash of your password.</li>
    <li>The IP address you signed up from, to stop duplicate accounts.</li>
    <li>Your balance, the offers you were credited for, and your redeem requests, including the payout address you enter.</li>
  </ul>

  <h2>Offerwall partner</h2>
  <p>Offers are provided by Moonetix (moonetix.com). When you open the offerwall, Moonetix receives your user ID on <?= e($site) ?>,
    your IP address, and device and browser data, and tracks the offers you start, in order to credit completed offers and prevent fraud.
    Advertisers receive data according to their own policies when you start an offer. See the
    <a href="https://moonetix.com/privacy" rel="noopener">Moonetix privacy policy</a>.</p>

  <h2>Payouts</h2>
  <p>To pay you, we share the amount and your payout address with the payout provider you choose.</p>

  <h2>Cookies</h2>
  <p>We use one session cookie to keep you logged in. No advertising cookies are set by this site.</p>

  <h2>Your rights and contact</h2>
  <p>Add how users can see, export and delete their data, how long you keep it, and how to reach you.</p>
</article>
<?php
page_end();

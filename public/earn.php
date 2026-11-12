<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

$user = require_login();

try {
    // The user ID comes from the session only. sub1 tells you in reports which page opened the wall.
    $wall = moonetix_wall_link(config('MOONETIX_APP_ID'), config('MOONETIX_LINK_SALT'), (string)$user['id'], ['sub1' => 'earn_page']);
} catch (Throwable $e) {
    error_log('offerwall link: ' . $e->getMessage());
    $wall = null;
}

page_start('Earn', 'wide');
?>
<section class="earn-head">
  <div>
    <h1>Earn <?= e(config('CURRENCY_NAME')) ?></h1>
    <p class="muted">Complete an offer and your <?= e(config('CURRENCY_NAME')) ?> arrive once the advertiser confirms it, usually within minutes.
      Some offers take longer. Track every offer you started under My offers inside the offerwall, and report a missing reward there.</p>
  </div>
  <?php if ($wall): ?><a class="button secondary" href="<?= e($wall) ?>" target="_blank" rel="noopener">Open in a new tab</a><?php endif ?>
</section>
<?php if ($wall): ?>
  <iframe class="offerwall" title="Offerwall" src="<?= e($wall) ?>"></iframe>
<?php else: ?>
  <p class="error">The offerwall is not configured yet. Set MOONETIX_APP_ID and MOONETIX_LINK_SALT.</p>
<?php endif ?>
<?php
page_end();

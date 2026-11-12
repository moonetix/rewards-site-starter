<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

$user = require_login();

$st = db()->prepare('SELECT amount_micro, kind, ref, note, created_at FROM ledger WHERE user_id = ? ORDER BY id DESC LIMIT 200');
$st->execute([$user['id']]);
$rows = $st->fetchAll();

$labels = ['offer' => 'Offer completed', 'reversal' => 'Reversed by the advertiser', 'redeem' => 'Redeem request', 'redeem_refund' => 'Redeem request returned'];

page_start('History');
?>
<h1>History</h1>
<p class="muted">Every change to your balance. A reversal takes back a reward the advertiser did not confirm in the end, for example after a refund.</p>
<?php if (!$rows): ?>
  <p>No activity yet. <a href="/earn.php">Start earning</a>.</p>
<?php else: ?>
  <table>
    <thead><tr><th>Date</th><th>What</th><th>Details</th><th class="num"><?= e(config('CURRENCY_NAME')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e(gmdate('Y-m-d H:i', (int)$r['created_at'])) ?> UTC</td>
        <td><?= e($labels[$r['kind']] ?? $r['kind']) ?></td>
        <td><?= e($r['note']) ?></td>
        <td class="num <?= (int)$r['amount_micro'] < 0 ? 'neg' : 'pos' ?>"><?= (int)$r['amount_micro'] > 0 ? '+' : '' ?><?= e(format_amount((int)$r['amount_micro'])) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>
<?php
page_end();

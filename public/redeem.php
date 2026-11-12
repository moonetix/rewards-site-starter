<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

$user = require_login();
$methods = array_values(array_filter(array_map('trim', explode(',', config('REDEEM_METHODS')))));
$currency = config('CURRENCY_NAME');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = trim((string)($_POST['amount'] ?? ''));
    $amount = ctype_digit($raw) && strlen($raw) <= 12 ? (int)$raw * MICRO : null;
    $method = (string)($_POST['method'] ?? '');
    $destination = trim((string)($_POST['destination'] ?? ''));

    if (!rate_limit('redeem:' . $user['id'], 5, 3600)) {
        http_response_code(429);
        $error = 'Too many requests. Try again in an hour.';
    } elseif ($amount === null || $amount <= 0) {
        $error = 'Enter a whole number of ' . $currency . '.';
    } elseif (!in_array($method, $methods, true)) {
        $error = 'Choose a payout method.';
    } elseif ($destination === '' || mb_strlen($destination) > 200) {
        $error = 'Enter where we should send it, for example your PayPal email.';
    } else {
        $result = request_redeem((int)$user['id'], $amount, $method, $destination);
        if ($result['ok']) {
            flash('Request received. We review every request by hand before paying.');
            redirect('/redeem.php');
        }
        $error = $result['error'];
    }
}

$available = available_micro(db(), (int)$user['id']);
$held = max(0, (int)current_user()['balance_micro'] - $available);
$st = db()->prepare('SELECT id, amount_micro, method, status, admin_note, created_at FROM redeem_requests WHERE user_id = ? ORDER BY id DESC LIMIT 50');
$st->execute([$user['id']]);
$requests = $st->fetchAll();

page_start('Redeem');
?>
<h1>Redeem</h1>
<div class="grid">
  <form method="post" class="card">
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif ?>
    <?= csrf_field() ?>
    <p>Available now: <strong><?= e(format_amount($available)) ?> <?= e($currency) ?></strong>
      <?php if ($held > 0): ?><br><span class="muted"><?= e(format_amount($held)) ?> <?= e($currency) ?> are on hold for <?= e(config('REDEEM_HOLD_DAYS')) ?> days after they were earned.</span><?php endif ?></p>
    <label>Amount (minimum <?= e(format_amount((int)config('REDEEM_MIN_COINS') * MICRO)) ?>)
      <input type="text" name="amount" inputmode="numeric" pattern="[0-9]+" required></label>
    <label>Method
      <select name="method" required>
        <?php foreach ($methods as $m): ?><option value="<?= e($m) ?>"><?= e($m) ?></option><?php endforeach ?>
      </select></label>
    <label>Send to <input type="text" name="destination" maxlength="200" placeholder="PayPal email or gift card email" required></label>
    <button type="submit">Request payout</button>
    <p class="muted">Every request is reviewed by hand before it is paid. Rewards that an advertiser reverses are taken back from your balance.</p>
  </form>
  <section class="card">
    <h2>Your requests</h2>
    <?php if (!$requests): ?><p class="muted">None yet.</p><?php else: ?>
    <table>
      <thead><tr><th>Date</th><th class="num"><?= e($currency) ?></th><th>Method</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($requests as $r): ?>
        <tr>
          <td><?= e(gmdate('Y-m-d', (int)$r['created_at'])) ?></td>
          <td class="num"><?= e(format_amount((int)$r['amount_micro'])) ?></td>
          <td><?= e($r['method']) ?></td>
          <td><?= e(ucfirst($r['status'])) ?><?= $r['admin_note'] !== '' ? '<br><span class="muted">' . e($r['admin_note']) . '</span>' : '' ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <?php endif ?>
  </section>
</div>
<?php
page_end();

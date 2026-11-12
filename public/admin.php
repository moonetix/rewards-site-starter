<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

$user = require_login();
if (!is_admin($user)) {
    http_response_code(403);
    render_page('Not allowed', '<p>This page is for administrators.</p>');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    $ok = decide_redeem($id, $decision, $note);
    flash($ok ? "Request #$id marked as $decision." : "Request #$id was already decided.");
    redirect('/admin.php');
}

// The numbers that matter before paying: account age, how much was ever credited, and how much was reversed.
$pending = db()->query("
    SELECT r.id, r.amount_micro, r.method, r.destination, r.created_at,
           u.id AS user_id, u.email, u.balance_micro, u.signup_ip, u.created_at AS user_created,
           (SELECT COALESCE(SUM(reward_micro), 0) FROM reward_tx t WHERE t.user_id = u.id AND t.status = 1) AS credited_micro,
           (SELECT COUNT(*) FROM reward_tx t WHERE t.user_id = u.id AND t.status = 2 AND t.reward_micro > 0) AS reversals,
           (SELECT COUNT(*) FROM users o WHERE o.signup_ip = u.signup_ip AND o.id <> u.id) AS same_ip_accounts,
           (SELECT COUNT(*) FROM redeem_requests p WHERE p.user_id = u.id AND p.status = 'paid') AS paid_before
    FROM redeem_requests r JOIN users u ON u.id = r.user_id
    WHERE r.status = 'pending' ORDER BY r.id")->fetchAll();
$recent = db()->query("SELECT r.id, r.amount_micro, r.method, r.status, r.decided_at, u.email
                       FROM redeem_requests r JOIN users u ON u.id = r.user_id
                       WHERE r.status <> 'pending' ORDER BY r.decided_at DESC LIMIT 25")->fetchAll();

page_start('Admin', 'wide');
?>
<h1>Redeem requests</h1>
<p class="muted">Nothing is paid automatically. Pay through your provider first, then mark the request as paid.
  Rejecting a request returns the <?= e(config('CURRENCY_NAME')) ?> to the user.</p>
<?php if (!$pending): ?>
  <p>No pending requests.</p>
<?php else: ?>
  <table class="admin">
    <thead><tr><th>#</th><th>User</th><th class="num">Amount</th><th>Pay to</th><th>Checks</th><th>Decision</th></tr></thead>
    <tbody>
    <?php foreach ($pending as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= e($r['email']) ?><br><span class="muted">ID <?= (int)$r['user_id'] ?>, joined <?= e(gmdate('Y-m-d', (int)$r['user_created'])) ?></span></td>
        <td class="num"><?= e(format_amount((int)$r['amount_micro'])) ?></td>
        <td><?= e($r['method']) ?><br><span class="muted"><?= e($r['destination']) ?></span></td>
        <td class="muted">
          Credited ever: <?= e(format_amount((int)$r['credited_micro'])) ?><br>
          Reversals: <?= (int)$r['reversals'] ?><br>
          Balance after request: <?= e(format_amount((int)$r['balance_micro'])) ?><br>
          Other accounts from sign-up IP: <?= (int)$r['same_ip_accounts'] ?><br>
          Paid before: <?= (int)$r['paid_before'] ?>
        </td>
        <td>
          <form method="post" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="text" name="note" maxlength="500" placeholder="Note to the user (optional)">
            <button type="submit" name="decision" value="paid">Mark paid</button>
            <button type="submit" name="decision" value="rejected" class="secondary">Reject and refund</button>
          </form>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>

<h2>Recently decided</h2>
<?php if (!$recent): ?><p class="muted">Nothing yet.</p><?php else: ?>
<table>
  <thead><tr><th>#</th><th>User</th><th class="num">Amount</th><th>Method</th><th>Status</th><th>Decided</th></tr></thead>
  <tbody>
  <?php foreach ($recent as $r): ?>
    <tr><td><?= (int)$r['id'] ?></td><td><?= e($r['email']) ?></td><td class="num"><?= e(format_amount((int)$r['amount_micro'])) ?></td>
      <td><?= e($r['method']) ?></td><td><?= e(ucfirst($r['status'])) ?></td><td><?= e(gmdate('Y-m-d H:i', (int)$r['decided_at'])) ?></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
<?php
page_end();

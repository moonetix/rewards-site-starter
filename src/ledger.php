<?php
declare(strict_types=1);

/**
 * Applies one verified postback. Returns credited, reversed, duplicate, unknown_user,
 * or not_credited (a reversal of a transaction we never credited: stored with amount 0,
 * so a late copy of the credit cannot pay).
 */
function apply_postback(string $tx, int $userId, int $rewardMicro, int $status, string $offerName): string
{
    return in_transaction(function (PDO $pdo) use ($tx, $userId, $rewardMicro, $status, $offerName): string {
        $now = time();
        $st = $pdo->prepare('SELECT user_id, reward_micro, status FROM reward_tx WHERE transaction_id = ?');
        $st->execute([$tx]);
        $existing = $st->fetch();
        $insert = $pdo->prepare('INSERT INTO reward_tx (transaction_id, user_id, reward_micro, status, offer_name, created_at, updated_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)');

        if ($status === 1) {
            if ($existing) {
                return 'duplicate';
            }
            $user = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
            $user->execute([$userId]);
            if (!$user->fetchColumn()) {
                return 'unknown_user';
            }
            $insert->execute([$tx, $userId, $rewardMicro, 1, $offerName, $now, $now]);
            change_balance($pdo, $userId, $rewardMicro, 'offer', $tx, $offerName);
            return 'credited';
        }

        if (!$existing) {
            $insert->execute([$tx, $userId, 0, 2, $offerName, $now, $now]);
            return 'not_credited';
        }
        if ((int)$existing['status'] === 2) {
            return 'duplicate';
        }
        $pdo->prepare('UPDATE reward_tx SET status = 2, updated_at = ? WHERE transaction_id = ?')->execute([$now, $tx]);
        // The stored amount and user, not the values in the reversal.
        change_balance($pdo, (int)$existing['user_id'], -(int)$existing['reward_micro'], 'reversal', $tx, $offerName);
        return 'reversed';
    });
}

function change_balance(PDO $pdo, int $userId, int $amountMicro, string $kind, string $ref, string $note = ''): void
{
    $pdo->prepare('UPDATE users SET balance_micro = balance_micro + ? WHERE id = ?')->execute([$amountMicro, $userId]);
    $pdo->prepare('INSERT INTO ledger (user_id, amount_micro, kind, ref, note, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $amountMicro, $kind, $ref, mb_substr($note, 0, 200), time()]);
}

/**
 * What a user may redeem now: the balance minus offer credits younger than the hold period.
 * Most reversals arrive within days, so fresh credits stay on hold.
 */
function available_micro(PDO $pdo, int $userId): int
{
    $st = $pdo->prepare('SELECT balance_micro FROM users WHERE id = ?');
    $st->execute([$userId]);
    $balance = (int)$st->fetchColumn();
    $holdDays = (int)config('REDEEM_HOLD_DAYS');
    if ($holdDays > 0) {
        $st = $pdo->prepare("SELECT COALESCE(SUM(reward_micro), 0) FROM reward_tx
                             WHERE user_id = ? AND status = 1 AND created_at > ?");
        $st->execute([$userId, time() - $holdDays * 86400]);
        $balance -= (int)$st->fetchColumn();
    }
    return max(0, $balance);
}

/** Creates a redeem request and takes the amount from the balance at once, so it cannot be spent twice. */
function request_redeem(int $userId, int $amountMicro, string $method, string $destination): array
{
    return in_transaction(function (PDO $pdo) use ($userId, $amountMicro, $method, $destination): array {
        $minimum = (int)config('REDEEM_MIN_COINS') * MICRO;
        if ($amountMicro < $minimum) {
            return ['ok' => false, 'error' => 'The minimum is ' . format_amount($minimum) . ' coins.'];
        }
        $st = $pdo->prepare("SELECT 1 FROM redeem_requests WHERE user_id = ? AND status = 'pending'");
        $st->execute([$userId]);
        if ($st->fetchColumn()) {
            return ['ok' => false, 'error' => 'You already have a request waiting for review.'];
        }
        if ($amountMicro > available_micro($pdo, $userId)) {
            return ['ok' => false, 'error' => 'You do not have that many coins available yet.'];
        }
        $pdo->prepare('INSERT INTO redeem_requests (user_id, amount_micro, method, destination, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $amountMicro, $method, $destination, time()]);
        $id = (int)$pdo->lastInsertId();
        change_balance($pdo, $userId, -$amountMicro, 'redeem', 'redeem:' . $id, $method);
        return ['ok' => true, 'id' => $id];
    });
}

/**
 * Admin decision. Paid only records that you paid by hand; rejected gives the coins back.
 * The admin's user ID is stored with the decision, so it can be traced later.
 */
function decide_redeem(int $requestId, string $decision, string $note, int $adminId): bool
{
    return in_transaction(function (PDO $pdo) use ($requestId, $decision, $note, $adminId): bool {
        $st = $pdo->prepare("SELECT user_id, amount_micro FROM redeem_requests WHERE id = ? AND status = 'pending'");
        $st->execute([$requestId]);
        $req = $st->fetch();
        if (!$req || !in_array($decision, ['paid', 'rejected'], true)) {
            return false;
        }
        $pdo->prepare('UPDATE redeem_requests SET status = ?, admin_note = ?, decided_at = ?, decided_by = ? WHERE id = ?')
            ->execute([$decision, mb_substr($note, 0, 500), time(), $adminId, $requestId]);
        if ($decision === 'rejected') {
            change_balance($pdo, (int)$req['user_id'], (int)$req['amount_micro'], 'redeem_refund', 'redeem:' . $requestId, $note);
        }
        return true;
    });
}

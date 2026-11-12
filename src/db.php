<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $path = config('DATABASE_PATH');
    if (!str_starts_with($path, '/')) {
        $path = APP_ROOT . '/' . $path; // relative to the project, not to whichever script runs
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 10000');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            email           TEXT NOT NULL,
            email_canonical TEXT NOT NULL UNIQUE,
            password_hash   TEXT NOT NULL,
            balance_micro   INTEGER NOT NULL DEFAULT 0,
            signup_ip       TEXT NOT NULL DEFAULT '',
            created_at      INTEGER NOT NULL,
            last_login_at   INTEGER
        );

        -- One row per Moonetix transaction. The primary key is what stops a second credit.
        CREATE TABLE IF NOT EXISTS reward_tx (
            transaction_id TEXT PRIMARY KEY,
            user_id        INTEGER NOT NULL,
            reward_micro   INTEGER NOT NULL,
            status         INTEGER NOT NULL CHECK (status IN (1, 2)),
            offer_name     TEXT NOT NULL DEFAULT '',
            created_at     INTEGER NOT NULL,
            updated_at     INTEGER NOT NULL
        );

        -- Every balance change, signed. The user's history page reads this table.
        CREATE TABLE IF NOT EXISTS ledger (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER NOT NULL REFERENCES users (id),
            amount_micro INTEGER NOT NULL,
            kind         TEXT NOT NULL CHECK (kind IN ('offer', 'reversal', 'redeem', 'redeem_refund')),
            ref          TEXT NOT NULL,
            note         TEXT NOT NULL DEFAULT '',
            created_at   INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS ledger_user ON ledger (user_id, id);

        CREATE TABLE IF NOT EXISTS redeem_requests (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER NOT NULL REFERENCES users (id),
            amount_micro INTEGER NOT NULL CHECK (amount_micro > 0),
            method       TEXT NOT NULL,
            destination  TEXT NOT NULL,
            status       TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'paid', 'rejected')),
            admin_note   TEXT NOT NULL DEFAULT '',
            created_at   INTEGER NOT NULL,
            decided_at   INTEGER
        );
        CREATE INDEX IF NOT EXISTS redeem_status ON redeem_requests (status, id);

        CREATE TABLE IF NOT EXISTS rate_limits (
            bucket       TEXT PRIMARY KEY,
            window_start INTEGER NOT NULL,
            hits         INTEGER NOT NULL
        );
        SQL);
}

/** Runs $fn inside BEGIN IMMEDIATE, so concurrent writers wait instead of interleaving. */
function in_transaction(callable $fn): mixed
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $result = $fn($pdo);
        $pdo->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** Fixed window counter in SQLite. Returns false once $max hits are used up in the window. */
function rate_limit(string $bucket, int $max, int $windowSeconds): bool
{
    return in_transaction(function (PDO $pdo) use ($bucket, $max, $windowSeconds): bool {
        $now = time();
        $st = $pdo->prepare('SELECT window_start, hits FROM rate_limits WHERE bucket = ?');
        $st->execute([$bucket]);
        $row = $st->fetch();
        if (!$row || $now - (int)$row['window_start'] >= $windowSeconds) {
            $pdo->prepare('INSERT OR REPLACE INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)')->execute([$bucket, $now]);
            return true;
        }
        $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?')->execute([$bucket]);
        return (int)$row['hits'] + 1 <= $max;
    });
}

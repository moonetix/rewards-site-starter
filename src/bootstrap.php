<?php
declare(strict_types=1);

require __DIR__ . '/moonetix.php';
require __DIR__ . '/db.php';
require __DIR__ . '/ledger.php';
require __DIR__ . '/view.php';

const APP_ROOT = __DIR__ . '/..';

load_env_file(APP_ROOT . '/.env');

/** Values from the environment, with defaults for everything that is not a secret. */
function config(string $name): string
{
    static $defaults = [
        'SITE_NAME' => 'Rewards Starter',
        'CURRENCY_NAME' => 'Coins',
        'DATABASE_PATH' => APP_ROOT . '/data/rewards.sqlite',
        'ADMIN_EMAILS' => '',
        'REDEEM_MIN_COINS' => '1000',
        'REDEEM_HOLD_DAYS' => '3',
        'REDEEM_METHODS' => 'PayPal,Amazon gift card',
        'REGISTER_LIMIT_PER_HOUR' => '3',
        'LOGIN_LIMIT_PER_15_MIN' => '10',
        'SESSION_SECURE' => 'auto',
        'CLIENT_IP_HEADER' => '',
        'MOONETIX_CREDIT_TEST_POSTBACKS' => 'false',
        'MOONETIX_APP_ID' => '',
        'MOONETIX_LINK_SALT' => '',
        'MOONETIX_POSTBACK_KEY' => '',
    ];
    $value = getenv($name);
    return $value === false || $value === '' ? ($defaults[$name] ?? '') : $value;
}

/** Minimal .env reader for hosts without one. Real environment variables win. */
function load_env_file(string $file): void
{
    if (!is_file($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k !== '' && getenv($k) === false) {
            putenv($k . '=' . trim($v, "\"'"));
        }
    }
}

function client_ip(): string
{
    // Behind Cloudflare or a proxy you trust, set CLIENT_IP_HEADER (for example CF-Connecting-IP).
    $header = config('CLIENT_IP_HEADER');
    if ($header !== '') {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        if (!empty($_SERVER[$key]) && filter_var($_SERVER[$key], FILTER_VALIDATE_IP)) {
            return $_SERVER[$key];
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function is_https(): bool
{
    $setting = config('SESSION_SECURE');
    if ($setting !== 'auto') {
        return $setting === 'true' || $setting === '1';
    }
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; frame-src https://wall.moonetix.com; script-src 'self' https://wall.moonetix.com; "
        . "style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cache-Control: no-store');
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = is_https();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    session_name($secure ? '__Host-rsid' : 'rsid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();

    // Idle timeout of 2 hours, absolute limit of 7 days.
    $now = time();
    if (isset($_SESSION['uid']) && ($now - ($_SESSION['seen'] ?? 0) > 7200 || $now - ($_SESSION['born'] ?? 0) > 604800)) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['seen'] = $now;
    $_SESSION['born'] ??= $now;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

/** Every POST must carry the session's token. A foreign Origin header is refused too. */
function require_post_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $sameOrigin = $origin === '' || parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') === ($_SERVER['HTTP_HOST'] ?? '');
    $token = $_POST['csrf'] ?? '';
    if (!$sameOrigin || !is_string($token) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        render_page('Session expired', '<p>Your session expired or the form was sent from another site. Go back, reload the page and try again.</p>');
        exit;
    }
}

function log_in(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
    $_SESSION['born'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([time(), $userId]);
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (isset($_SESSION['uid'])) {
            $st = db()->prepare('SELECT id, email, balance_micro, created_at FROM users WHERE id = ?');
            $st->execute([(int)$_SESSION['uid']]);
            $user = $st->fetch() ?: null;
        }
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: /login.php');
        exit;
    }
    return $user;
}

function is_admin(?array $user): bool
{
    if (!$user) {
        return false;
    }
    $admins = array_filter(array_map(fn ($e) => strtolower(trim($e)), explode(',', config('ADMIN_EMAILS'))));
    return in_array(strtolower($user['email']), $admins, true);
}

/**
 * One account per mailbox: lowercase, no +tag, and no dots for Gmail, where
 * j.ane+x@gmail.com and jane@gmail.com reach the same inbox.
 */
function canonical_email(string $email): string
{
    $email = strtolower(trim($email));
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $local = explode('+', $local, 2)[0];
    if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
        $local = str_replace('.', '', $local);
        $domain = 'gmail.com';
    }
    return $local . '@' . $domain;
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

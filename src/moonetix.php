<?php
// Moonetix offerwall link and postback checks.
// Formats: https://docs.moonetix.com/offerwall-link and https://docs.moonetix.com/postbacks

declare(strict_types=1);

const MOONETIX_WALL_BASE = 'https://wall.moonetix.com/';
const MOONETIX_USER_ID_RE = '/^[A-Za-z0-9._@:+-]{1,100}$/D';
const MOONETIX_SUB_ID_RE = '/^[A-Za-z0-9._~:@+-]{1,100}$/D';
const MICRO = 1000000; // one coin in integer units, so balances stay exact

/** The signed offerwall link for one user ID. The salt never leaves the server. */
function moonetix_wall_link(string $appId, string $linkSalt, string $userId, array $subs = []): string
{
    if ($appId === '' || $linkSalt === '') {
        throw new RuntimeException('MOONETIX_APP_ID and MOONETIX_LINK_SALT must be set');
    }
    if (!preg_match(MOONETIX_USER_ID_RE, $userId)) {
        throw new InvalidArgumentException('user ID not accepted by the offerwall');
    }
    $url = MOONETIX_WALL_BASE . rawurlencode($appId) . '?user_id=' . rawurlencode($userId)
        . '&hash=' . hash_hmac('sha256', $userId, $linkSalt);
    foreach ($subs as $key => $value) {
        if (preg_match('/^sub[1-5]$/D', (string)$key) && preg_match(MOONETIX_SUB_ID_RE, (string)$value)) {
            $url .= '&' . $key . '=' . rawurlencode((string)$value);
        }
    }
    return $url;
}

/**
 * Parses a raw query string and returns tx, user, reward, status and hash only if each one
 * appears exactly once. PHP's $_GET silently keeps the last of repeated keys, so it is not used.
 */
function moonetix_read_postback(string $queryString): ?array
{
    $wanted = ['tx' => [], 'user' => [], 'reward' => [], 'status' => [], 'hash' => []];
    foreach ($queryString === '' ? [] : explode('&', $queryString) as $pair) {
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        $k = urldecode($k);
        if (array_key_exists($k, $wanted)) {
            $wanted[$k][] = urldecode($v);
        }
    }
    $p = [];
    foreach ($wanted as $k => $values) {
        if (count($values) !== 1) {
            return null;
        }
        $p[$k] = $values[0];
    }
    return $p;
}

function moonetix_postback_hash(string $postbackKey, array $p): string
{
    return hash_hmac('sha256', $p['tx'] . ':' . $p['user'] . ':' . $p['reward'] . ':' . $p['status'], $postbackKey);
}

/** Constant-time check of the postback hash, on the values exactly as they arrived. */
function moonetix_valid_hash(string $postbackKey, array $p): bool
{
    if ($postbackKey === '') {
        throw new RuntimeException('MOONETIX_POSTBACK_KEY is not set');
    }
    return hash_equals(moonetix_postback_hash($postbackKey, $p), $p['hash']);
}

/** "150" -> 150000000, "0.375" -> 375000. Anything else -> null. No floats involved. */
function parse_amount(string $value): ?int
{
    if (!preg_match('/^(\d{1,12})(?:\.(\d{1,6}))?$/D', $value, $m)) {
        return null;
    }
    return (int)$m[1] * MICRO + (int)str_pad($m[2] ?? '', 6, '0');
}

/** 150375000 -> "150.375" */
function format_amount(int $micro): string
{
    $sign = $micro < 0 ? '-' : '';
    $abs = abs($micro);
    $frac = rtrim(str_pad((string)($abs % MICRO), 6, '0', STR_PAD_LEFT), '0');
    return $sign . number_format(intdiv($abs, MICRO)) . ($frac !== '' ? '.' . $frac : '');
}

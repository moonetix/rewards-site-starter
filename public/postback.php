<?php
// Moonetix postback endpoint. Save this URL on the Postback page of your Moonetix dashboard:
// https://YOUR_DOMAIN/postback.php?tx={transaction_id}&user={user_id}&reward={reward}&status={status}&offer={offer_name}&hash={hash}
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');

function answer(int $code, string $body): never
{
    http_response_code($code);
    echo $body;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    answer(405, 'method not allowed');
}

// 1. Hash first, on the raw values. Nothing is stored or trusted before this check.
$p = moonetix_read_postback((string)($_SERVER['QUERY_STRING'] ?? ''));
if ($p === null) {
    answer(403, 'missing or repeated parameter');
}
try {
    if (!moonetix_valid_hash(config('MOONETIX_POSTBACK_KEY'), $p)) {
        answer(403, 'invalid hash');
    }
} catch (RuntimeException $e) {
    error_log('moonetix postback: ' . $e->getMessage());
    answer(500, 'not configured');
}

// 2. Signed values that make no sense. Retrying would not fix them.
$reward = parse_amount($p['reward']);
if ($reward === null || !in_array($p['status'], ['1', '2'], true) || $p['tx'] === '' || strlen($p['tx']) > 255) {
    error_log('moonetix postback with unexpected values: ' . json_encode($p));
    answer(400, 'bad values');
}

// 3. Dashboard tests have a transaction ID that starts with test_.
if (str_starts_with($p['tx'], 'test_') && config('MOONETIX_CREDIT_TEST_POSTBACKS') !== 'true') {
    error_log('moonetix test postback (not credited): ' . $p['tx'] . ' user ' . $p['user'] . ' status ' . $p['status']);
    answer(200, 'test postback, not credited');
}

// 4. Our user IDs are positive integers. Anything else cannot be one of our users.
if (!preg_match('/^[1-9]\d{0,17}$/D', $p['user'])) {
    error_log('moonetix postback for unknown user ID ' . $p['user'] . ', tx ' . $p['tx']);
    answer(200, 'ignored: unknown user');
}

// 5. Store, then answer. 500 makes Moonetix retry (up to 5 times).
$offer = isset($_GET['offer']) && is_string($_GET['offer']) ? mb_substr($_GET['offer'], 0, 200) : '';
try {
    $result = apply_postback($p['tx'], (int)$p['user'], $reward, (int)$p['status'], $offer);
} catch (Throwable $e) {
    error_log('moonetix postback ' . $p['tx'] . ' failed: ' . $e->getMessage());
    answer(500, 'try again');
}
answer(200, $result);

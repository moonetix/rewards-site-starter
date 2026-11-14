<?php
// Test runner: php tests/run.php
// Part 1 calls the code directly. Part 2 starts the site on PHP's built-in server and drives it over HTTP:
// sign-up, login, CSRF, the offerwall link, signed postbacks, redeem requests and admin decisions.
//
// Postbacks are signed here with the documented formula. Set MOONETIX_GEN_CMD to a command that prints
// {"postback": url, "wall": url} for <template> <tx> <user> <reward> <status> <secret> <salt> <app_id>
// to test against another signer, for example the Moonetix postback code itself.

declare(strict_types=1);

const APP = 'demoapp0000000000000001';
const KEY = 'test_postback_key_not_secret';
const SALT = 'test_link_salt_not_secret';
const TEMPLATE = 'http://localhost/postback.php?tx={transaction_id}&user={user_id}&reward={reward}&status={status}&event={event_id}&offer={offer_name}&hash={hash}';

$tmp = sys_get_temp_dir() . '/rewards-starter-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
register_shutdown_function(function () use ($tmp) {
    foreach (glob($tmp . '/*') ?: [] as $f) {
        unlink($f);
    }
    rmdir($tmp);
});

$passed = 0;
$failed = 0;
function check(bool $ok, string $name): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "ok    $name\n";
    } else {
        $failed++;
        echo "FAIL  $name\n";
    }
}

function gen(string $tx, string $user, string $reward, int $status, string $secret = KEY, bool $local = false): array
{
    $cmd = $local ? '' : (string)getenv('MOONETIX_GEN_CMD');
    if ($cmd !== '') {
        $args = array_map('escapeshellarg', [TEMPLATE, $tx, $user, $reward, (string)$status, $secret, SALT, APP]);
        return json_decode((string)shell_exec($cmd . ' ' . implode(' ', $args)), true, 512, JSON_THROW_ON_ERROR);
    }
    $vars = ['transaction_id' => $tx, 'user_id' => $user, 'reward' => $reward, 'status' => (string)$status, 'event_id' => 'e1',
        'offer_name' => 'Demo offer', 'hash' => hash_hmac('sha256', "$tx:$user:$reward:$status", $secret)];
    $postback = preg_replace_callback('/\{(\w+)\}/', fn ($m) => rawurlencode($vars[$m[1]] ?? ''), TEMPLATE);
    $wall = 'https://wall.moonetix.com/' . APP . '?user_id=' . rawurlencode($user) . '&hash=' . hash_hmac('sha256', $user, SALT);
    return ['postback' => $postback, 'wall' => $wall];
}

// ---------------------------------------------------------------- Part 1: direct calls

putenv('DATABASE_PATH=' . $tmp . '/unit.sqlite');
putenv('MOONETIX_APP_ID=' . APP);
putenv('MOONETIX_LINK_SALT=' . SALT);
putenv('MOONETIX_POSTBACK_KEY=' . KEY);
putenv('REDEEM_HOLD_DAYS=3');
putenv('REDEEM_MIN_COINS=100');
require __DIR__ . '/../src/bootstrap.php';

check(moonetix_wall_link(APP, SALT, '42') === gen('tx', '42', '1', 1)['wall'], 'wall link matches the Moonetix link');
check(moonetix_wall_link(APP, SALT, '42', ['sub1' => 'earn_page', 'sub2' => 'bad value']) === gen('tx', '42', '1', 1)['wall'] . '&sub1=earn_page', 'sub IDs appended outside the hash');
$threw = 0;
foreach (['', 'a b', str_repeat('x', 101)] as $bad) {
    try {
        moonetix_wall_link(APP, SALT, $bad);
    } catch (InvalidArgumentException) {
        $threw++;
    }
}
check($threw === 3, 'invalid user IDs refused');

$q = (string)parse_url(gen('tx_1', '7', '0.375', 1)['postback'], PHP_URL_QUERY);
$p = moonetix_read_postback($q);
check($p !== null && $p['reward'] === '0.375' && moonetix_valid_hash(KEY, $p), 'real postback hash verifies');
check(moonetix_read_postback($q . '&tx=other') === null, 'repeated parameter refused');
check(moonetix_read_postback(preg_replace('/&hash=\w+/', '', $q)) === null, 'missing hash refused');
check(moonetix_read_postback(str_replace('tx=', 'tx[]=', $q)) === null, 'array parameter refused');
$tampered = moonetix_read_postback(str_replace('reward=0.375', 'reward=375', $q));
check($tampered !== null && !moonetix_valid_hash(KEY, $tampered), 'tampered reward fails the hash');
check(!moonetix_valid_hash('another_key', $p), 'other key fails the hash');

check(parse_amount('150') === 150000000 && parse_amount('0.375') === 375000 && parse_amount('1.23456') === 1234560, 'amounts parsed exactly');
check(parse_amount('-1') === null && parse_amount('1e3') === null && parse_amount('.5') === null && parse_amount('1.1234567') === null, 'bad amounts refused');
check(format_amount(parse_amount('0.1') + parse_amount('0.2')) === '0.3' && format_amount(1500000000) === '1,500', 'amounts formatted exactly');

check(canonical_email(' J.Ane+promo@GMail.com ') === 'jane@gmail.com', 'gmail dots and tags collapse to one mailbox');
check(canonical_email('Jo.Doe+x@example.org') === 'jo.doe@example.org', 'other domains keep dots, drop tags');

db()->prepare("INSERT INTO users (email, email_canonical, password_hash, created_at) VALUES ('u@example.com', 'u@example.com', 'x', ?)")->execute([time()]);
check(apply_postback('u_1', 1, 150000000, 1, 'Offer') === 'credited', 'unit: credit');
check(apply_postback('u_1', 1, 150000000, 1, 'Offer') === 'duplicate', 'unit: duplicate credit skipped');
check(available_micro(db(), 1) === 0, 'hold: a fresh credit is not redeemable yet');
db()->exec("UPDATE reward_tx SET created_at = created_at - 4 * 86400 WHERE transaction_id = 'u_1'");
check(available_micro(db(), 1) === 150000000, 'hold: redeemable after the hold period');
check(request_redeem(1, 50 * MICRO, 'PayPal', 'u@example.com')['ok'] === false, 'redeem: below the minimum refused');
$r = request_redeem(1, 120 * MICRO, 'PayPal', 'u@example.com');
check($r['ok'] === true, 'redeem: request accepted');
check(request_redeem(1, 10 * MICRO, 'PayPal', 'u@example.com')['ok'] === false, 'redeem: one pending request at a time');
check(apply_postback('u_1', 1, 150000000, 2, 'Offer') === 'reversed', 'unit: reversal after redeem');
check((int)db()->query('SELECT balance_micro FROM users WHERE id = 1')->fetchColumn() === -120 * MICRO, 'reversal can take the balance below zero');
check(decide_redeem($r['id'], 'rejected', 'Reversed offer', 1) && !decide_redeem($r['id'], 'paid', '', 1), 'admin: reject once, then nothing');
check((int)db()->query('SELECT balance_micro FROM users WHERE id = 1')->fetchColumn() === 0, 'reject refunds the request');
check((int)db()->query('SELECT decided_by FROM redeem_requests WHERE id = ' . (int)$r['id'])->fetchColumn() === 1, 'admin: the decision stores who made it');

$old = new PDO('sqlite:' . $tmp . '/old.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$old->exec("CREATE TABLE redeem_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, amount_micro INTEGER NOT NULL,
    method TEXT NOT NULL, destination TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', admin_note TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL, decided_at INTEGER)");
$old->exec("INSERT INTO redeem_requests (user_id, amount_micro, method, destination, created_at) VALUES (1, 5, 'PayPal', 'x', 1)");
migrate($old);
migrate($old);
$row = $old->query('SELECT * FROM redeem_requests')->fetchAll(PDO::FETCH_ASSOC);
check(count($row) === 1 && array_key_exists('decided_by', $row[0]) && $row[0]['decided_by'] === null, 'older database gains the decided_by column, rows kept');

// ---------------------------------------------------------------- Part 2: over HTTP

$port = 20000 + random_int(0, 20000);
$base = "http://127.0.0.1:$port";
$env = array_merge(getenv(), [
    'DATABASE_PATH' => $tmp . '/http.sqlite',
    'MOONETIX_APP_ID' => APP, 'MOONETIX_LINK_SALT' => SALT, 'MOONETIX_POSTBACK_KEY' => KEY,
    'ADMIN_EMAILS' => 'admin@example.com', 'REDEEM_HOLD_DAYS' => '0', 'REDEEM_MIN_COINS' => '100',
    'REGISTER_LIMIT_PER_HOUR' => '50', 'LOGIN_LIMIT_PER_15_MIN' => '5', 'SESSION_SECURE' => 'false',
    'PHP_CLI_SERVER_WORKERS' => '4',
]);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', __DIR__ . '/../public'], [
    1 => ['file', $tmp . '/server.log', 'a'], 2 => ['file', $tmp . '/server.log', 'a'],
], $pipes, null, $env);
register_shutdown_function(fn () => proc_terminate($server));
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

final class Browser
{
    private CurlHandle $h;
    public function __construct(private string $base)
    {
        $this->h = curl_init();
        curl_setopt_array($this->h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false]);
    }
    public function get(string $path): array
    {
        curl_setopt_array($this->h, [CURLOPT_URL => $this->base . $path, CURLOPT_HTTPGET => true]);
        return $this->run();
    }
    public function post(string $path, array $fields, bool $withCsrf = true, array $headers = [], ?string $csrfFrom = null): array
    {
        if ($withCsrf) {
            $fields['csrf'] = $this->csrf($csrfFrom ?? $path);
        }
        curl_setopt_array($this->h, [CURLOPT_URL => $this->base . $path, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_HTTPHEADER => $headers]);
        $r = $this->run();
        curl_setopt($this->h, CURLOPT_HTTPHEADER, []);
        return $r;
    }
    public function csrf(string $path): string
    {
        preg_match('/name="csrf" value="([0-9a-f]+)"/', $this->get($path)['body'], $m);
        return $m[1] ?? '';
    }
    private function run(): array
    {
        $raw = (string)curl_exec($this->h);
        $size = curl_getinfo($this->h, CURLINFO_HEADER_SIZE);
        return ['status' => curl_getinfo($this->h, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    }
}

function postback(string $url): array
{
    global $base;
    $h = curl_init($base . '/postback.php?' . parse_url($url, PHP_URL_QUERY));
    curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
    $body = (string)curl_exec($h);
    return [curl_getinfo($h, CURLINFO_RESPONSE_CODE), $body];
}
function balance_of(Browser $b): string
{
    preg_match('/class="pill">([^<]+)</', $b->get('/history.php')['body'], $m);
    return trim(html_entity_decode($m[1] ?? ''));
}

$home = (new Browser($base))->get('/');
check($home['status'] === 200 && str_contains($home['headers'], "frame-src https://wall.moonetix.com"), 'home page with CSP');
check((new Browser($base))->get('/earn.php')['status'] === 302, 'earn page needs a login');

$alice = new Browser($base);
$r = $alice->post('/register.php', ['email' => 'alice@example.com', 'password' => 'correct horse battery', 'age' => 'yes', 'terms' => 'yes'], false);
check($r['status'] === 403, 'sign-up without CSRF token refused');
$r = $alice->post('/register.php', ['email' => 'alice@example.com', 'password' => 'correct horse battery', 'terms' => 'yes']);
check($r['status'] === 200 && str_contains($r['body'], '16 or older'), 'sign-up without age confirmation refused');
$r = $alice->post('/register.php', ['email' => 'alice@example.com', 'password' => 'short', 'age' => 'yes', 'terms' => 'yes']);
check(str_contains($r['body'], '10 to 200'), 'short password refused');
$r = $alice->post('/register.php', ['email' => 'alice@example.com', 'password' => 'correct horse battery', 'age' => 'yes', 'terms' => 'yes']);
check($r['status'] === 303 && str_contains($r['headers'], 'Location: /earn.php'), 'sign-up works');
check((bool)preg_match('/Set-Cookie: rsid=[^;]+;.*HttpOnly; SameSite=Lax/i', $r['headers']), 'session cookie is HttpOnly and SameSite=Lax');

$earn = $alice->get('/earn.php');
$expected = htmlspecialchars(gen('tx', '1', '1', 1)['wall'] . '&sub1=earn_page', ENT_QUOTES);
check($earn['status'] === 200 && str_contains($earn['body'], 'src="' . $expected . '"'), 'earn page iframe has the Moonetix-signed link');
check(!str_contains($earn['body'], SALT) && !str_contains($earn['body'], KEY), 'no secret in the page');

$dup = new Browser($base);
$r = $dup->post('/register.php', ['email' => 'Alice+second@Example.com', 'password' => 'another long password', 'age' => 'yes', 'terms' => 'yes']);
check(str_contains($r['body'], 'already exists'), 'one account per email (case and +tag ignored)');

$cross = new Browser($base);
$token = $cross->csrf('/login.php');
$r = $cross->post('/login.php', ['email' => 'alice@example.com', 'password' => 'correct horse battery', 'csrf' => $token], false, ['Origin: https://evil.example']);
check($r['status'] === 403, 'cross-site form post refused');

$guess = new Browser($base);
$codes = [];
for ($i = 0; $i < 6; $i++) {
    $codes[] = $guess->post('/login.php', ['email' => 'alice@example.com', 'password' => 'wrong password ' . $i])['status'];
}
check($codes[0] === 200 && end($codes) === 429, 'login rate limit kicks in');

[$code, $body] = postback(gen('tx_1', '1', '150', 1)['postback']);
check($code === 200 && $body === 'credited', 'postback credit');
[$code, $body] = postback(gen('tx_1', '1', '150', 1)['postback']);
check($code === 200 && $body === 'duplicate', 'postback retry skipped');
postback(gen('tx_2', '1', '0.375', 1)['postback']);
postback(gen('tx_3', '1', '1.23456', 1)['postback']);
check(balance_of($alice) === '151.60956 Coins', 'decimal rewards add up exactly: ' . balance_of($alice));

$urls = array_fill(0, 20, $base . '/postback.php?' . parse_url(gen('tx_par', '1', '315', 1)['postback'], PHP_URL_QUERY));
$mh = curl_multi_init();
$handles = [];
foreach ($urls as $u) {
    $h = curl_init($u);
    curl_setopt($h, CURLOPT_RETURNTRANSFER, true);
    curl_multi_add_handle($mh, $h);
    $handles[] = $h;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);
$bodies = array_map('curl_multi_getcontent', $handles);
check(count(array_keys($bodies, 'credited')) === 1 && count(array_keys($bodies, 'duplicate')) === 19, '20 parallel copies credit once');
check(balance_of($alice) === '466.60956 Coins', 'balance after parallel copies');

[$code, $body] = postback(gen('tx_1', '1', '150', 2)['postback']);
check($code === 200 && $body === 'reversed', 'reversal');
[$code, $body] = postback(gen('tx_1', '1', '150', 2)['postback']);
check($body === 'duplicate' && balance_of($alice) === '316.60956 Coins', 'repeated reversal skipped');
[$code, $body] = postback(gen('tx_rb', '1', '50', 2)['postback']);
[$code2, $body2] = postback(gen('tx_rb', '1', '50', 1)['postback']);
check($body === 'not_credited' && $body2 === 'duplicate' && balance_of($alice) === '316.60956 Coins', 'reversal before credit blocks the late credit');
check(postback(gen('test_abc', '1', '100', 1)['postback'])[1] === 'test postback, not credited', 'test_ postback logged, not credited');
check(postback(gen('tx_u', '999', '100', 1)['postback'])[1] === 'unknown_user', 'unknown user ignored');
check(postback(gen('tx_n', 'test_user', '100', 1)['postback'])[1] === 'ignored: unknown user', 'non-numeric user ignored');

$good = gen('tx_t', '1', '150', 1)['postback'];
check(postback(str_replace('reward=150', 'reward=15000', $good))[0] === 403, 'tampered reward: 403');
check(postback(gen('tx_t', '1', '150', 1, 'other_key')['postback'])[0] === 403, 'wrong key: 403');
check(postback($good . '&tx=tx_other')[0] === 403, 'repeated tx: 403');
check(postback(preg_replace('/hash=\w+/', 'hash=abc', $good))[0] === 403, 'short hash: 403');
check(postback(gen('tx_t', '1', '1e5', 1, KEY, true)['postback'])[0] === 400, 'signed but malformed reward: 400');
check(balance_of($alice) === '316.60956 Coins', 'refused postbacks changed nothing');

$r = $alice->post('/redeem.php', ['amount' => '50', 'method' => 'PayPal', 'destination' => 'alice@example.com']);
check(str_contains($r['body'], 'The minimum is 100'), 'redeem below minimum refused');
$r = $alice->post('/redeem.php', ['amount' => '300', 'method' => 'Bitcoin', 'destination' => 'x']);
check(str_contains($r['body'], 'Choose a payout method'), 'unknown payout method refused');
$r = $alice->post('/redeem.php', ['amount' => '300', 'method' => 'PayPal', 'destination' => 'alice@example.com']);
check($r['status'] === 303 && balance_of($alice) === '16.60956 Coins', 'redeem request takes the coins at once');
$r = $alice->post('/redeem.php', ['amount' => '100', 'method' => 'PayPal', 'destination' => 'alice@example.com']);
check(str_contains($r['body'], 'already have a request'), 'second pending request refused');
check($alice->get('/admin.php')['status'] === 403, 'admin page closed to users');
check(str_contains($alice->get('/history.php')['body'], 'Redeem request'), 'history lists the request');

$admin = new Browser($base);
$admin->post('/register.php', ['email' => 'admin@example.com', 'password' => 'admin password 123', 'age' => 'yes', 'terms' => 'yes']);
$page = $admin->get('/admin.php');
check($page['status'] === 200 && str_contains($page['body'], 'alice@example.com') && str_contains($page['body'], 'Reversals: 1'), 'admin sees the request with checks');
$r = $admin->post('/admin.php', ['id' => '1', 'decision' => 'paid', 'note' => 'Sent']);
check($r['status'] === 303 && str_contains($admin->get('/admin.php')['body'], 'No pending requests'), 'admin marks it paid');
check(str_contains($admin->get('/admin.php')['body'], '<td>admin@example.com</td>'), 'admin list shows who decided');
check(str_contains($alice->get('/redeem.php')['body'], 'Paid'), 'user sees Paid');
check(balance_of($alice) === '16.60956 Coins', 'paying does not change the balance again');

$r = $alice->post('/logout.php', [], true, [], '/earn.php');
check($r['status'] === 303 && $alice->get('/earn.php')['status'] === 302, 'logout ends the session');
check((new Browser($base))->get('/logout.php')['status'] === 405, 'logout needs POST');
foreach (['/terms.php', '/privacy.php', '/login.php', '/register.php'] as $path) {
    check((new Browser($base))->get($path)['status'] === 200, "page $path renders");
}
$log = (string)file_get_contents($tmp . '/server.log');
check(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/', $log), 'no PHP warnings in the server log');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

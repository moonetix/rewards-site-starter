<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

if (current_user()) {
    redirect('/earn.php');
}

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $canonical = canonical_email($email);
    $limit = (int)config('LOGIN_LIMIT_PER_15_MIN');

    if (!rate_limit('login-ip:' . client_ip(), $limit, 900) || !rate_limit('login-user:' . $canonical, $limit, 900)) {
        http_response_code(429);
        $error = 'Too many attempts. Wait 15 minutes and try again.';
    } else {
        $st = db()->prepare('SELECT id, password_hash FROM users WHERE email_canonical = ?');
        $st->execute([$canonical]);
        $user = $st->fetch();
        // Unknown emails still pay for one hash, so response time does not reveal which emails exist.
        $hash = $user['password_hash'] ?? password_hash(random_bytes(16), PASSWORD_DEFAULT);
        if (password_verify($password, $hash) && $user) {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            log_in((int)$user['id']);
            redirect('/earn.php');
        }
        $error = 'Wrong email or password.';
    }
}

page_start('Log in');
?>
<form method="post" class="card narrow">
  <h1>Log in</h1>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif ?>
  <?= csrf_field() ?>
  <label>Email <input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required></label>
  <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
  <button type="submit">Log in</button>
  <p class="muted">New here? <a href="/register.php">Create an account</a></p>
</form>
<?php
page_end();

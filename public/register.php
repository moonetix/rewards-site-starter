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

    if (!rate_limit('register:' . client_ip(), (int)config('REGISTER_LIMIT_PER_HOUR'), 3600)) {
        http_response_code(429);
        $error = 'Too many sign-ups from your network. Try again later.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 10 || strlen($password) > 200) {
        $error = 'Use a password of 10 to 200 characters.';
    } elseif (($_POST['age'] ?? '') !== 'yes') {
        $error = 'You must be 16 or older to earn rewards.';
    } elseif (($_POST['terms'] ?? '') !== 'yes') {
        $error = 'Please accept the terms.';
    } else {
        try {
            db()->prepare('INSERT INTO users (email, email_canonical, password_hash, signup_ip, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([strtolower($email), $canonical, password_hash($password, PASSWORD_DEFAULT), client_ip(), time()]);
            log_in((int)db()->lastInsertId());
            flash('Welcome. Complete offers below to earn ' . config('CURRENCY_NAME') . '.');
            redirect('/earn.php');
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'UNIQUE')) {
                throw $e;
            }
            $error = 'An account already exists for this email address. Log in instead.';
        }
    }
}

page_start('Sign up');
?>
<form method="post" class="card narrow" novalidate>
  <h1>Create your account</h1>
  <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif ?>
  <?= csrf_field() ?>
  <label>Email <input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required></label>
  <label>Password <input type="password" name="password" minlength="10" autocomplete="new-password" required></label>
  <label class="check"><input type="checkbox" name="age" value="yes" required> I am 16 or older. If I am under 18, a parent agrees.</label>
  <label class="check"><input type="checkbox" name="terms" value="yes" required> I accept the <a href="/terms.php">terms</a>, including one account per person.</label>
  <button type="submit">Create account</button>
  <p class="muted">Already registered? <a href="/login.php">Log in</a></p>
</form>
<?php
page_end();

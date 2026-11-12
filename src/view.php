<?php
declare(strict_types=1);

function e(string|int|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function page_start(string $title, string $bodyClass = ''): void
{
    $user = current_user();
    $site = config('SITE_NAME');
    $nav = $user
        ? '<a href="/earn.php">Earn</a><a href="/history.php">History</a><a href="/redeem.php">Redeem</a>'
            . (is_admin($user) ? '<a href="/admin.php">Admin</a>' : '')
            . '<form method="post" action="/logout.php" class="inline">' . csrf_field() . '<button type="submit" class="link">Log out</button></form>'
        : '<a href="/login.php">Log in</a><a href="/register.php" class="button small">Sign up</a>';
    $balance = $user ? '<span class="pill">' . e(format_amount((int)$user['balance_micro'])) . ' ' . e(config('CURRENCY_NAME')) . '</span>' : '';
    $flash = flash();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' | ' . e($site) . '</title>'
        . '<link rel="stylesheet" href="/assets/style.css"></head>'
        . '<body class="' . e($bodyClass) . '"><header class="top"><a class="brand" href="/">' . e($site) . '</a>'
        . '<nav>' . $balance . $nav . '</nav></header><main>'
        . ($flash ? '<p class="notice" role="status">' . e($flash) . '</p>' : '');
}

function page_end(): void
{
    echo '</main><footer><a href="/terms.php">Terms</a><a href="/privacy.php">Privacy</a>'
        . '<span>Offers by <a href="https://moonetix.com" rel="noopener">Moonetix</a></span></footer></body></html>';
}

function render_page(string $title, string $html): void
{
    page_start($title);
    echo '<h1>' . e($title) . '</h1>' . $html;
    page_end();
}

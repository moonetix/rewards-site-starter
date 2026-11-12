<?php
declare(strict_types=1);

require __DIR__ . '/../src/web.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$_SESSION = [];
session_regenerate_id(true);
session_destroy();
redirect('/');

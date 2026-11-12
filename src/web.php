<?php
// Included at the top of every page (not the postback endpoint).
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

send_security_headers();
start_session();
require_post_csrf();

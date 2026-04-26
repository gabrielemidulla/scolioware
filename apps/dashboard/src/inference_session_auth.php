<?php

declare(strict_types=1);

/**
 * Nginx auth_request: 200 if session valid, 401 otherwise. Not a user-facing page.
 */
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';

if (php_sapi_name() === 'cli') {
    http_response_code(400);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => sv_session_cookie_secure(),
    ]);
    session_name('SV_SESSION');
    @session_start(['read_and_close' => true]);
}

$pid = isset($_SESSION['physician_id']) ? (int) $_SESSION['physician_id'] : 0;
if ($pid <= 0) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8', true, 401);
    exit;
}

try {
    $u = sv_find_physician($pid);
} catch (Throwable) {
    $u = null;
}
if (!$u || $u['deleted_at'] !== null
    || (isset($u['password_must_change']) && (int) $u['password_must_change'] === 1)) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8', true, 401);
    exit;
}

http_response_code(200);
header('Content-Type: text/plain; charset=UTF-8', true, 200);
echo 'ok';
exit;

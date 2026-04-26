#!/usr/bin/env php
<?php

/**
 * One-time: create the first admin user. Run only from CLI, e.g. in the `php` container:
 *   docker compose -f infra/docker-compose.yaml exec php php /var/www/html/seed_admin.php
 *
 * Requires migration `007_p0_security_audit.sql` (column `password_must_change`).
 * Environment:
 *   SV_SEED_ADMIN_PASSWORD  (required)
 *   SV_SEED_ADMIN_USER      (optional, default `admin`)
 *   SV_SEED_ADMIN_MUST_CHANGE=0|1  (default 1: force change password on first login)
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$dir = __DIR__;
require $dir . '/inc/db.php';

$pw = (string) (getenv('SV_SEED_ADMIN_PASSWORD') ?: '');
$username = (string) (getenv('SV_SEED_ADMIN_USER') ?: 'admin');
$mustChange = getenv('SV_SEED_ADMIN_MUST_CHANGE') === '0' ? 0 : 1;

if ($pw === '' || $username === '') {
    fwrite(STDERR, "seed_admin: set SV_SEED_ADMIN_PASSWORD (and optionally SV_SEED_ADMIN_USER).\n");
    exit(1);
}
if (strlen($pw) < 6) {
    fwrite(STDERR, "seed_admin: password must be at least 6 characters.\n");
    exit(1);
}

try {
    $count = (int) db()->query('SELECT COUNT(*) FROM physicians WHERE deleted_at IS NULL AND is_admin = 1')->fetchColumn();
} catch (Throwable $e) {
    fwrite(STDERR, 'seed_admin: database error: ' . $e->getMessage() . "\n");
    exit(1);
}
if ($count > 0) {
    fwrite(STDERR, "seed_admin: an admin user already exists — not creating another.\n");
    exit(1);
}

$hash = password_hash($pw, PASSWORD_BCRYPT);
try {
    $stmt = db()->prepare(
        "INSERT INTO physicians (username, password_hash, is_admin, display_name, password_must_change)
         VALUES (?, ?, 1, 'Administrator', ?)"
    );
    $stmt->execute([$username, $hash, $mustChange]);
} catch (Throwable $e) {
    fwrite(STDERR, 'seed_admin: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "seed_admin: created admin " . $username
    . ( $mustChange ? " (set a new password on first sign-in via /account.php).\n" : ".\n"));
exit(0);

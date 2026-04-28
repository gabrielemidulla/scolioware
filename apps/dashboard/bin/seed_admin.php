#!/usr/bin/env php
<?php

/** CLI: create first admin. Production requires SW_ADMIN_USER and SW_ADMIN_PASSWORD. */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$ifEmpty = in_array('--if-empty', $argv ?? [], true);

$dir = dirname(__DIR__);
require $dir . '/vendor/autoload.php';
require_once $dir . '/src/Auth/legacy_auth.php';

$isProd = strtolower(trim((string) (getenv('APP_ENV') ?: getenv('APP_ENVIRONMENT') ?: ''))) === 'prod';

$userRaw = trim((string) (getenv('SW_ADMIN_USER') ?: ''));
$pwRaw = trim((string) (getenv('SW_ADMIN_PASSWORD') ?: ''));
$explicitCreds = $userRaw !== '' && $pwRaw !== '';

if ($explicitCreds) {
    $username = $userRaw;
    $pw = $pwRaw;
    $mustChange = 0;
    $minLen = SW_MIN_PASSWORD_LEN;
} else {
    $username = 'admin';
    $pw = 'admin12345';
    $mustChange = 1;
    $minLen = SW_MIN_PASSWORD_LEN;
}

try {
    $count = (int) \App\Infrastructure\Database::pdo()->query('SELECT COUNT(*) FROM physicians WHERE deleted_at IS NULL AND is_admin = 1')->fetchColumn();
} catch (Throwable $e) {
    fwrite(STDERR, 'seed_admin: database error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($count > 0) {
    if ($ifEmpty) {
        fwrite(STDERR, "seed_admin: admin already exists, skipping (--if-empty).\n");
        exit(0);
    }
    fwrite(STDERR, "seed_admin: an admin user already exists — not creating another.\n");
    exit(1);
}

if ($isProd && !$explicitCreds) {
    fwrite(STDERR, "seed_admin: production requires SW_ADMIN_USER and SW_ADMIN_PASSWORD (no default credentials).\n");
    exit(1);
}

if (strlen($pw) < $minLen) {
    if ($ifEmpty) {
        fwrite(
            STDERR,
            "seed_admin: password must be at least {$minLen} characters for this mode; skipping (--if-empty).\n"
        );
        exit(0);
    }
    fwrite(STDERR, "seed_admin: password must be at least {$minLen} characters.\n");
    exit(1);
}

if ($username === '') {
    if ($ifEmpty) {
        fwrite(STDERR, "seed_admin: username empty after trim; skipping (--if-empty).\n");
        exit(0);
    }
    fwrite(STDERR, "seed_admin: username must be non-empty.\n");
    exit(1);
}

$hash = password_hash($pw, PASSWORD_BCRYPT);
try {
    $stmt = \App\Infrastructure\Database::pdo()->prepare(
        "INSERT INTO physicians (username, password_hash, is_admin, display_name, password_must_change)
         VALUES (?, ?, 1, 'Administrator', ?)"
    );
    $stmt->execute([$username, $hash, $mustChange]);
} catch (Throwable $e) {
    fwrite(STDERR, 'seed_admin: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, 'seed_admin: created admin ' . $username
    . ($mustChange ? " (set a new password on first sign-in via /account.php).\n" : ".\n"));
exit(0);

<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/Locale.php';

$lang = isset($_GET['lang']) ? strtolower(trim((string) $_GET['lang'])) : '';
if (!in_array($lang, Locale::SUPPORTED, true)) {
    header('Location: index.php', true, 302);
    exit;
}

$return = isset($_GET['return']) ? (string) $_GET['return'] : '';
if ($return === '' || $return[0] !== '/' || str_contains($return, '://') || str_contains($return, "\0")) {
    $return = '/index.php';
} elseif (!preg_match('#^/[A-Za-z0-9_\-./?=&%]+$#D', $return)) {
    $return = '/index.php';
}

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

$expires = time() + 365 * 24 * 3600;
if (PHP_VERSION_ID >= 70300) {
    setcookie(Locale::COOKIE_NAME, $lang, [
        'expires' => $expires,
        'path' => '/',
        'secure' => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
} else {
    setcookie(Locale::COOKIE_NAME, $lang, $expires, '/', '', $secure, false);
}

header('Location: ' . $return, true, 302);
exit;

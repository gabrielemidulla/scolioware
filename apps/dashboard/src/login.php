<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/Locale.php';

sv_session_start();
Locale::init();

try {
    sv_ensure_admin();
} catch (Throwable $e) {
}

if (sv_current_user() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sv_csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = __('login.error_required');
    } else {
        $row = sv_find_physician_by_username($username);
        if ($row && password_verify($password, $row['password_hash'])) {
            if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT)) {
                sv_set_password((int) $row['id'], $password);
            }
            sv_login_user($row);
            $next = isset($_GET['next']) ? (string) $_GET['next'] : 'index.php';
            if (!preg_match('#^/[A-Za-z0-9_\-./?=&%]*$#', $next)) {
                $next = 'index.php';
            }
            header('Location: ' . $next);
            exit;
        }
        $error = __('login.error_invalid');
    }
}

$csrf = sv_csrf_token();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Locale::htmlLang(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__('login.page_title'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="sv-auth-body">
<main class="sv-auth-shell">
    <div class="sv-auth-inline">
        <div class="sv-auth-visual" aria-hidden="true"></div>
        <div class="sv-auth-card">
        <div class="sv-auth-brand">
            <img class="sv-brand-logo" src="assets/img/brand-scoliosoft-teal.svg" width="365" height="65" alt="Scoliosoft">
        </div>
        <h1 class="visually-hidden"><?= htmlspecialchars(__('login.heading'), ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger py-2 mb-3" role="alert">
                <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="mb-3">
                <label class="form-label"><?= htmlspecialchars(__('login.label_username'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="username" required maxlength="64"
                       autofocus autocomplete="username"
                       value="<?= htmlspecialchars($username) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label"><?= htmlspecialchars(__('login.label_password'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" type="password" name="password" required
                       autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="fa-solid fa-right-to-bracket fa-fw"></i> <?= htmlspecialchars(__('login.submit'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </form>
        <p class="sv-auth-help mt-3 mb-0 text-muted">
            <?= htmlspecialchars(__('login.help_reset'), ENT_QUOTES, 'UTF-8') ?>
        </p>
        </div>
    </div>
</main>
<div class="sv-auth-locale-dock">
    <?php Locale::renderLanguageSwitcher(); ?>
</div>
</body>
</html>

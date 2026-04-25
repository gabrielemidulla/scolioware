<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/Locale.php';

sv_session_start();
Locale::init();

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
if ($token === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string) ($_POST['token'] ?? ''));
}

$row = $token !== '' ? sv_consume_reset_token($token) : null;

$error = '';
$success = false;

if ($row === null) {
    $error = __('reset.error_token');
}

if ($row !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sv_csrf_check();
    $new1 = (string) ($_POST['new_password'] ?? '');
    $new2 = (string) ($_POST['new_password_confirm'] ?? '');
    if ($new1 !== $new2) {
        $error = __('reset.error_mismatch');
    } elseif (($pwErr = sv_validate_password($new1)) !== null) {
        $error = $pwErr;
    } else {
        sv_set_password((int) $row['physician_id'], $new1);
        sv_mark_reset_token_used((int) $row['id']);
        $success = true;
    }
}

$csrf = sv_csrf_token();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Locale::htmlLang(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(__('reset.title'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="sv-auth-body">
<main class="sv-auth-shell">
    <div class="sv-auth-card">
        <div class="sv-auth-locale mb-2">
            <?php Locale::renderLanguageSwitcher(); ?>
        </div>
        <div class="sv-auth-brand">
            <i class="fa-solid fa-bone"></i>
            <span>Scoliosoft</span>
        </div>
        <h1 class="sv-auth-title"><?= htmlspecialchars(__('reset.h1'), ENT_QUOTES, 'UTF-8') ?></h1>

        <?php if ($success): ?>
            <div class="alert alert-success py-2">
                <i class="fa-solid fa-circle-check"></i>
                <?= htmlspecialchars(__('reset.success_lead'), ENT_QUOTES, 'UTF-8') ?>
                <a href="login.php"><?= htmlspecialchars(__('reset.sign_in_link'), ENT_QUOTES, 'UTF-8') ?></a>.
            </div>
        <?php else: ?>
            <?php if ($row !== null): ?>
                <p class="text-muted small mb-3">
                    <?= htmlspecialchars(__('reset.for_user', ['name' => (string) $row['username']]), ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($row !== null): ?>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars(__('reset.label_new'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" type="password" name="new_password" required
                               minlength="<?= SV_MIN_PASSWORD_LEN ?>" autocomplete="new-password" autofocus>
                        <div class="form-text"><?= htmlspecialchars(__('reset.min_chars', ['min' => (string) SV_MIN_PASSWORD_LEN]), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars(__('reset.label_confirm'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" type="password" name="new_password_confirm" required
                               minlength="<?= SV_MIN_PASSWORD_LEN ?>" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa-solid fa-floppy-disk fa-fw"></i> <?= htmlspecialchars(__('reset.btn_set'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <p class="sv-auth-help mt-3 mb-0 text-muted">
            <a href="login.php"><i class="fa-solid fa-arrow-left"></i> <?= htmlspecialchars(__('reset.back_sign_in'), ENT_QUOTES, 'UTF-8') ?></a>
        </p>
    </div>
</main>
</body>
</html>

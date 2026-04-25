<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';

sv_session_start();

$token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
if ($token === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string) ($_POST['token'] ?? ''));
}

$row = $token !== '' ? sv_consume_reset_token($token) : null;

$error = '';
$success = false;

if ($row === null) {
    $error = 'This link is invalid, expired, or already used. Ask the administrator for a new one.';
}

if ($row !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    sv_csrf_check();
    $new1 = (string) ($_POST['new_password'] ?? '');
    $new2 = (string) ($_POST['new_password_confirm'] ?? '');
    if ($new1 !== $new2) {
        $error = 'Passwords do not match.';
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset password · Scoliosoft</title>
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
        <div class="sv-auth-brand">
            <i class="fa-solid fa-bone"></i>
            <span>Scoliosoft</span>
        </div>
        <h1 class="sv-auth-title">Reset password</h1>

        <?php if ($success): ?>
            <div class="alert alert-success py-2">
                <i class="fa-solid fa-circle-check"></i> Password updated. You can now <a href="login.php">sign in</a>.
            </div>
        <?php else: ?>
            <?php if ($row !== null): ?>
                <p class="text-muted small mb-3">
                    Setting a new password for <strong><?= htmlspecialchars($row['username']) ?></strong>.
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
                        <label class="form-label">New password</label>
                        <input class="form-control" type="password" name="new_password" required
                               minlength="<?= SV_MIN_PASSWORD_LEN ?>" autocomplete="new-password" autofocus>
                        <div class="form-text">Minimum <?= SV_MIN_PASSWORD_LEN ?> characters.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm new password</label>
                        <input class="form-control" type="password" name="new_password_confirm" required
                               minlength="<?= SV_MIN_PASSWORD_LEN ?>" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa-solid fa-floppy-disk fa-fw"></i> Set password
                    </button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <p class="sv-auth-help mt-3 mb-0 text-muted">
            <a href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to sign in</a>
        </p>
    </div>
</main>
</body>
</html>

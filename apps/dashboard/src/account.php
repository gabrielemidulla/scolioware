<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';

$user = sv_require_auth();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sv_csrf_check();
    $current = (string) ($_POST['current_password'] ?? '');
    $new1 = (string) ($_POST['new_password'] ?? '');
    $new2 = (string) ($_POST['new_password_confirm'] ?? '');

    if (!password_verify($current, $user['password_hash'])) {
        $error = __('account.error_current');
    } elseif ($new1 !== $new2) {
        $error = __('account.error_mismatch');
    } elseif (($pwErr = sv_validate_password($new1)) !== null) {
        $error = $pwErr;
    } else {
        sv_set_password((int) $user['id'], $new1);
        $success = __('account.success');
        $user = sv_find_physician((int) $user['id']);
    }
}

$csrf = sv_csrf_token();
sv_layout_start(__('account.title'));
sv_topbar(
    [
        sv_crumb(__('crumb.dashboard'), 'index.php'),
        sv_crumb(__('account.title'), null),
    ],
    '<i class="fa-solid fa-key"></i> ' . __('account.title'),
    null
);
?>

<div class="sv-card" style="max-width: 540px;">
    <div class="sv-card-header">
        <i class="fa-solid fa-user-doctor"></i> <?= htmlspecialchars($user['username']) ?>
        <?php if ((int) $user['is_admin'] === 1): ?>
            <span class="text-warning small"><?= htmlspecialchars(__('nav.admin_badge'), ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </div>
    <div class="sv-card-body">
        <?php if ($success !== ''): ?>
            <div class="alert alert-success py-2"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger py-2"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="mb-3">
                <label class="form-label"><?= htmlspecialchars(__('account.label_current'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" type="password" name="current_password" required
                       autocomplete="current-password">
            </div>
            <div class="mb-3">
                <label class="form-label"><?= htmlspecialchars(__('account.label_new'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" type="password" name="new_password" required
                       minlength="<?= SV_MIN_PASSWORD_LEN ?>" autocomplete="new-password">
                <div class="form-text"><?= htmlspecialchars(__('account.min_help', ['min' => (string) SV_MIN_PASSWORD_LEN]), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="mb-3">
                <label class="form-label"><?= htmlspecialchars(__('account.label_confirm'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" type="password" name="new_password_confirm" required
                       minlength="<?= SV_MIN_PASSWORD_LEN ?>" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-floppy-disk fa-fw"></i> <?= htmlspecialchars(__('account.submit'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </form>
    </div>
</div>
<?php sv_layout_end(); ?>

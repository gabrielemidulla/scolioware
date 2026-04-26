<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/phi_log.php';

$admin = sv_require_admin();
sv_phi_log('view_physicians', null, null, null);
$pdo = db();

$flash = '';
$flashType = 'info';
$generatedReset = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sv_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            $username = strtolower(trim((string) ($_POST['username'] ?? '')));
            $display = trim((string) ($_POST['display_name'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $isAdmin = isset($_POST['is_admin']) ? 1 : 0;

            if (!preg_match('/^[a-z0-9_.-]{3,64}$/', $username)) {
                throw new RuntimeException(__('physicians.err_user'));
            }
            if (($pwErr = sv_validate_password($password)) !== null) {
                throw new RuntimeException($pwErr);
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM physicians WHERE username = ? AND deleted_at IS NULL'
            );
            $stmt->execute([$username]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException(__('physicians.err_in_use'));
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                'INSERT INTO physicians (username, password_hash, is_admin, display_name)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$username, $hash, $isAdmin, $display !== '' ? $display : null]);

            $flash = __('physicians.ok_created', ['name' => $username]);
            $flashType = 'success';
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException(__('physicians.err_id'));
            }
            if ($id === (int) $admin['id']) {
                throw new RuntimeException(__('physicians.err_self_del'));
            }
            $target = sv_find_physician($id);
            if (!$target || $target['deleted_at'] !== null) {
                throw new RuntimeException(__('physicians.err_not_found'));
            }
            if ((int) $target['is_admin'] === 1) {
                $remaining = (int) $pdo->query(
                    'SELECT COUNT(*) FROM physicians
                     WHERE deleted_at IS NULL AND is_admin = 1'
                )->fetchColumn();
                if ($remaining <= 1) {
                    throw new RuntimeException(__('physicians.err_last_admin'));
                }
            }
            $stmt = $pdo->prepare(
                'UPDATE physicians SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?'
            );
            $stmt->execute([$id]);
            $flash = __('physicians.ok_deleted', ['name' => (string) $target['username']]);
            $flashType = 'success';
        } elseif ($action === 'restore') {
            $id = (int) ($_POST['id'] ?? 0);
            $target = sv_find_physician($id);
            if (!$target) {
                throw new RuntimeException(__('physicians.err_not_found'));
            }
            if ($target['deleted_at'] === null) {
                throw new RuntimeException(__('physicians.err_not_deleted'));
            }
            $clash = $pdo->prepare(
                'SELECT id FROM physicians WHERE username = ? AND deleted_at IS NULL LIMIT 1'
            );
            $clash->execute([$target['username']]);
            if ($clash->fetch()) {
                throw new RuntimeException(
                    __('physicians.err_restore', ['name' => (string) $target['username']])
                );
            }
            $stmt = $pdo->prepare('UPDATE physicians SET deleted_at = NULL WHERE id = ?');
            $stmt->execute([$id]);
            $flash = __('physicians.ok_restored', ['name' => (string) $target['username']]);
            $flashType = 'success';
        } elseif ($action === 'reset_link') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) $admin['id']) {
                throw new RuntimeException(__('physicians.err_reset_self'));
            }
            $target = sv_find_physician($id);
            if (!$target || $target['deleted_at'] !== null) {
                throw new RuntimeException(__('physicians.err_not_found'));
            }
            $token = sv_create_reset_token((int) $target['id'], (int) $admin['id']);
            $generatedReset = [
                'username' => $target['username'],
                'url' => sv_reset_link($token),
                'ttl_hours' => SV_TOKEN_TTL_HOURS,
            ];
            $flash = __('physicians.ok_reset', ['name' => (string) $target['username']]);
            $flashType = 'success';
        } else {
            throw new RuntimeException(__('physicians.err_unknown'));
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'danger';
    }
}

$rows = $pdo->query(
    'SELECT id, username, display_name, is_admin, created_at, deleted_at
     FROM physicians
     ORDER BY deleted_at IS NOT NULL ASC, is_admin DESC, username ASC'
)->fetchAll();

$csrf = sv_csrf_token();
sv_layout_start(__('physicians.title'));
sv_topbar(
    [
        sv_crumb(__('nav.dashboard'), 'index.php'),
        sv_crumb(__('physicians.title'), null),
    ],
    '<i class="fa-solid fa-user-doctor"></i> ' . htmlspecialchars((string) __('physicians.title'), ENT_QUOTES, 'UTF-8'),
    null
);
?>

<?php if ($flash !== ''): ?>
    <div class="alert alert-<?= htmlspecialchars($flashType) ?> py-2">
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<?php if ($generatedReset !== null): ?>
    <div class="sv-card mb-3">
        <div class="sv-card-header">
            <i class="fa-solid fa-link"></i> <?= htmlspecialchars(
                (string) __('physicians.reset_link_title', ['name' => (string) $generatedReset['username']]),
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
        <div class="sv-card-body">
            <p class="mb-2 text-muted small">
                <?= htmlspecialchars(
                    (string) __('physicians.reset_send', ['hours' => (int) $generatedReset['ttl_hours']]),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </p>
            <div class="input-group">
                <input type="text" class="form-control" value="<?= htmlspecialchars($generatedReset['url']) ?>" id="reset_url" readonly>
                <button class="btn btn-secondary" type="button" data-copied-lbl="<?= htmlspecialchars((string) __('physicians.copied'), ENT_QUOTES, 'UTF-8') ?>"
                    onclick="var b=this;var i=document.getElementById('reset_url');i.select();i.setSelectionRange(0,99999);
                    navigator.clipboard.writeText(i.value).then(function(){var s=b.querySelector('.js-copy-lbl');if(s)s.textContent=b.getAttribute('data-copied-lbl');});"
                >
                    <i class="fa-solid fa-copy"></i> <span class="js-copy-lbl"><?= htmlspecialchars((string) __('physicians.copy'), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="sv-card">
            <div class="sv-card-header">
                <i class="fa-solid fa-user-plus"></i> <?= htmlspecialchars((string) __('physicians.create'), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="sv-card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars((string) __('physicians.label_user'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" name="username" required maxlength="64"
                               pattern="[a-zA-Z0-9_.\-]{3,64}" placeholder="lowercase, 3–64">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars((string) __('physicians.label_display'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" name="display_name" maxlength="191">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars((string) __('physicians.label_pass'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" type="password" name="password" required
                               minlength="<?= SV_MIN_PASSWORD_LEN ?>">
                        <div class="form-text"><?= htmlspecialchars(
                            (string) __('physicians.hint_pass', ['min' => SV_MIN_PASSWORD_LEN]),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?></div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="is_admin" name="is_admin" value="1">
                        <label class="form-check-label" for="is_admin"><?= htmlspecialchars((string) __('physicians.admin'), ENT_QUOTES, 'UTF-8') ?></label>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk fa-fw"></i> <?= htmlspecialchars((string) __('physicians.create_btn'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="sv-card">
            <div class="sv-card-header">
                <i class="fa-solid fa-list"></i> <?= htmlspecialchars((string) __('physicians.list'), ENT_QUOTES, 'UTF-8') ?>
                <span class="text-muted fw-normal">(<?= count($rows) ?>)</span>
            </div>
            <div class="sv-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0 align-middle">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars((string) __('physicians.col_user'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars((string) __('physicians.col_display'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars((string) __('physicians.col_role'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars((string) __('physicians.col_created'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars((string) __('physicians.col_state'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th class="text-end"><?= htmlspecialchars((string) __('physicians.col_actions'), ENT_QUOTES, 'UTF-8') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r):
                            $rid = (int) $r['id'];
                            $isSelf = $rid === (int) $admin['id'];
                            $isDeleted = $r['deleted_at'] !== null;
                        ?>
                            <tr<?= $isDeleted ? ' class="text-muted"' : '' ?>>
                                <td>
                                    <code><?= htmlspecialchars($r['username']) ?></code>
                                    <?php if ($isSelf): ?>
                                        <span class="badge text-bg-secondary ms-1"><?= htmlspecialchars((string) __('physicians.you'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php
                                    $dn = (string) ($r['display_name'] ?? '');
                                    echo $dn === ''
                                        ? htmlspecialchars((string) __('common.dash'), ENT_QUOTES, 'UTF-8')
                                        : htmlspecialchars($dn);
                                ?></td>
                                <td>
                                    <?php if ((int) $r['is_admin'] === 1): ?>
                                        <span class="sv-status sv-status-completed">
                                            <i class="fa-solid fa-user-shield sv-status-icon"></i>
                                            <span class="sv-status-text"><?= htmlspecialchars((string) __('physicians.role_admin'), ENT_QUOTES, 'UTF-8') ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted"><?= htmlspecialchars((string) __('physicians.role_md'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="text-muted small"><?= htmlspecialchars((string) $r['created_at']) ?></span></td>
                                <td>
                                    <?php if ($isDeleted): ?>
                                        <span class="sv-status sv-status-failed">
                                            <i class="fa-solid fa-trash sv-status-icon"></i>
                                            <span class="sv-status-text"><?= htmlspecialchars((string) __('physicians.state_del'), ENT_QUOTES, 'UTF-8') ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="sv-status sv-status-completed">
                                            <i class="fa-solid fa-circle-check sv-status-icon"></i>
                                            <span class="sv-status-text"><?= htmlspecialchars((string) __('physicians.state_active'), ENT_QUOTES, 'UTF-8') ?></span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if (!$isDeleted): ?>
                                        <?php if (!$isSelf): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="reset_link">
                                            <input type="hidden" name="id" value="<?= $rid ?>">
                                            <button class="btn btn-secondary btn-sm" type="submit"
                                                    title="<?= htmlspecialchars((string) __('physicians.reset_title'), ENT_QUOTES, 'UTF-8') ?>">
                                                <i class="fa-solid fa-link"></i> <?= htmlspecialchars((string) __('physicians.reset'), ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline"
                                              onsubmit="return confirm(<?= json_encode(
                                                  (string) __('physicians.confirm_delete', ['name' => (string) $r['username']]),
                                                  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                                              ) ?>);">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $rid ?>">
                                            <button class="btn btn-secondary btn-sm" type="submit">
                                                <i class="fa-solid fa-trash"></i> <?= htmlspecialchars((string) __('physicians.delete'), ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="id" value="<?= $rid ?>">
                                            <button class="btn btn-secondary btn-sm" type="submit">
                                                <i class="fa-solid fa-rotate-left"></i> <?= htmlspecialchars((string) __('physicians.restore'), ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php sv_layout_end(); ?>

<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';

$admin = sv_require_admin();
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
                throw new RuntimeException('Username must be 3–64 chars: lowercase letters, digits, _ . -');
            }
            if (($pwErr = sv_validate_password($password)) !== null) {
                throw new RuntimeException($pwErr);
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM physicians WHERE username = ? AND deleted_at IS NULL'
            );
            $stmt->execute([$username]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('Username already in use.');
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                'INSERT INTO physicians (username, password_hash, is_admin, display_name)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$username, $hash, $isAdmin, $display !== '' ? $display : null]);

            $flash = 'Created physician "' . $username . '".';
            $flashType = 'success';
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Missing physician id.');
            }
            if ($id === (int) $admin['id']) {
                throw new RuntimeException('You cannot delete your own account.');
            }
            $target = sv_find_physician($id);
            if (!$target || $target['deleted_at'] !== null) {
                throw new RuntimeException('Physician not found.');
            }
            if ((int) $target['is_admin'] === 1) {
                $remaining = (int) $pdo->query(
                    'SELECT COUNT(*) FROM physicians
                     WHERE deleted_at IS NULL AND is_admin = 1'
                )->fetchColumn();
                if ($remaining <= 1) {
                    throw new RuntimeException('Cannot delete the last remaining admin.');
                }
            }
            $stmt = $pdo->prepare(
                'UPDATE physicians SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?'
            );
            $stmt->execute([$id]);
            $flash = 'Physician "' . $target['username'] . '" was soft-deleted.';
            $flashType = 'success';
        } elseif ($action === 'restore') {
            $id = (int) ($_POST['id'] ?? 0);
            $target = sv_find_physician($id);
            if (!$target) {
                throw new RuntimeException('Physician not found.');
            }
            if ($target['deleted_at'] === null) {
                throw new RuntimeException('Physician is not deleted.');
            }
            $clash = $pdo->prepare(
                'SELECT id FROM physicians WHERE username = ? AND deleted_at IS NULL LIMIT 1'
            );
            $clash->execute([$target['username']]);
            if ($clash->fetch()) {
                throw new RuntimeException('Cannot restore: username "' . $target['username'] . '" is already taken by an active physician.');
            }
            $stmt = $pdo->prepare('UPDATE physicians SET deleted_at = NULL WHERE id = ?');
            $stmt->execute([$id]);
            $flash = 'Physician "' . $target['username'] . '" was restored.';
            $flashType = 'success';
        } elseif ($action === 'reset_link') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) $admin['id']) {
                throw new RuntimeException('You cannot generate a reset link for your own account.');
            }
            $target = sv_find_physician($id);
            if (!$target || $target['deleted_at'] !== null) {
                throw new RuntimeException('Physician not found.');
            }
            $token = sv_create_reset_token((int) $target['id'], (int) $admin['id']);
            $generatedReset = [
                'username' => $target['username'],
                'url' => sv_reset_link($token),
                'ttl_hours' => SV_TOKEN_TTL_HOURS,
            ];
            $flash = 'Reset link generated for "' . $target['username'] . '". Copy and send it to the physician.';
            $flashType = 'success';
        } else {
            throw new RuntimeException('Unknown action.');
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
sv_layout_start('Physicians');
sv_topbar(
    [
        sv_crumb('Dashboard', 'index.php'),
        sv_crumb('Physicians', null),
    ],
    '<i class="fa-solid fa-user-doctor"></i> Physicians',
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
            <i class="fa-solid fa-link"></i> Reset link for "<?= htmlspecialchars($generatedReset['username']) ?>"
        </div>
        <div class="sv-card-body">
            <p class="mb-2 text-muted small">
                Send this URL to the physician. It expires in <?= (int) $generatedReset['ttl_hours'] ?> hours and can be used only once.
            </p>
            <div class="input-group">
                <input type="text" class="form-control" value="<?= htmlspecialchars($generatedReset['url']) ?>" id="reset_url" readonly>
                <button class="btn btn-secondary" type="button" onclick="
                    var i = document.getElementById('reset_url'); i.select(); i.setSelectionRange(0, 99999);
                    navigator.clipboard.writeText(i.value).then(function(){ this.textContent='Copied'; }.bind(this));
                ">
                    <i class="fa-solid fa-copy"></i> Copy
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="sv-card">
            <div class="sv-card-header">
                <i class="fa-solid fa-user-plus"></i> Create physician
            </div>
            <div class="sv-card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input class="form-control" name="username" required maxlength="64"
                               pattern="[a-zA-Z0-9_.\-]{3,64}" placeholder="lowercase, 3–64 chars">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Display name (optional)</label>
                        <input class="form-control" name="display_name" maxlength="191">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Initial password</label>
                        <input class="form-control" type="password" name="password" required
                               minlength="<?= SV_MIN_PASSWORD_LEN ?>">
                        <div class="form-text">Minimum <?= SV_MIN_PASSWORD_LEN ?> characters. The physician can change it later.</div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="is_admin" name="is_admin" value="1">
                        <label class="form-check-label" for="is_admin">Admin</label>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk fa-fw"></i> Create
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="sv-card">
            <div class="sv-card-header">
                <i class="fa-solid fa-list"></i> All physicians
                <span class="text-muted fw-normal">(<?= count($rows) ?>)</span>
            </div>
            <div class="sv-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Display name</th>
                                <th>Role</th>
                                <th>Created</th>
                                <th>State</th>
                                <th class="text-end">Actions</th>
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
                                        <span class="badge text-bg-secondary ms-1">you</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars((string) ($r['display_name'] ?? '—')) ?></td>
                                <td>
                                    <?php if ((int) $r['is_admin'] === 1): ?>
                                        <span class="sv-status sv-status-completed">
                                            <i class="fa-solid fa-user-shield sv-status-icon"></i>
                                            <span class="sv-status-text">admin</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">physician</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="text-muted small"><?= htmlspecialchars((string) $r['created_at']) ?></span></td>
                                <td>
                                    <?php if ($isDeleted): ?>
                                        <span class="sv-status sv-status-failed">
                                            <i class="fa-solid fa-trash sv-status-icon"></i>
                                            <span class="sv-status-text">deleted</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="sv-status sv-status-completed">
                                            <i class="fa-solid fa-circle-check sv-status-icon"></i>
                                            <span class="sv-status-text">active</span>
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
                                                    title="Generate one-time reset link">
                                                <i class="fa-solid fa-link"></i> Reset link
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline"
                                              onsubmit="return confirm('Soft-delete &quot;<?= htmlspecialchars($r['username']) ?>&quot;?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $rid ?>">
                                            <button class="btn btn-secondary btn-sm" type="submit">
                                                <i class="fa-solid fa-trash"></i> Delete
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                            <input type="hidden" name="action" value="restore">
                                            <input type="hidden" name="id" value="<?= $rid ?>">
                                            <button class="btn btn-secondary btn-sm" type="submit">
                                                <i class="fa-solid fa-rotate-left"></i> Restore
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

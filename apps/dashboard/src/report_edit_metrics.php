<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/back.php';

sv_require_auth();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0 && isset($_POST['report_id'])) {
    $id = (int) $_POST['report_id'];
}
if ($id <= 0) {
    http_response_code(400);
    echo htmlspecialchars((string) __('edit.err_id'), ENT_QUOTES, 'UTF-8');
    exit;
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT r.*, p.first_name, p.last_name, p.tax_code
     FROM reports r JOIN patients p ON p.id = r.patient_id WHERE r.id = ?'
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo htmlspecialchars((string) __('edit.err_nf'), ENT_QUOTES, 'UTF-8');
    exit;
}

$status = (string) $row['status'];
$editable = $status === 'failed' || $status === 'completed';
if (!$editable) {
    sv_layout_start((string) __('edit.cannot'));
    $backEarly = htmlspecialchars(sv_back_or('queue.php'), ENT_QUOTES, 'UTF-8');
    sv_topbar(
        [
            sv_crumb((string) __('nav.dashboard'), 'index.php'),
            sv_crumb((string) __('report.title', ['id' => (string) $id]), null),
        ],
        '<i class="fa-solid fa-triangle-exclamation"></i> ' . htmlspecialchars((string) __('edit.cannot'), ENT_QUOTES, 'UTF-8'),
        '<a href="' . $backEarly . '" class="btn btn-secondary">' . htmlspecialchars((string) __('common.back'), ENT_QUOTES, 'UTF-8') . '</a>'
    );
    ?>
    <div class="alert alert-warning">
        <?= (string) __('edit.warn', ['status' => $status]) ?>
    </div>
    <a href="<?= htmlspecialchars(sv_back_or('queue.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary"><?= htmlspecialchars(
        (string) __('common.back'),
        ENT_QUOTES,
        'UTF-8'
    ) ?></a>
    <?php
    sv_layout_end();
    exit;
}

$errors = [];
$postedBack = isset($_POST['back']) ? (string) $_POST['back'] : null;
$safeBack = sv_back_validate($postedBack) ?? sv_back_validate(sv_back_get_raw());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sv_csrf_check();

    $curve = trim((string) ($_POST['curve_type'] ?? ''));
    if ($curve === '') {
        $curveDb = null;
    } elseif ($curve === 'C' || $curve === 'S') {
        $curveDb = $curve;
    } else {
        $errors[] = (string) __('edit.err_curve');
        $curveDb = $row['curve_type'];
    }

    $nums = [
        'cobb_pt_deg',
        'cobb_mt_deg',
        'cobb_tl_deg',
        'cobb_thoracic_deg',
        'cobb_lumbar_deg',
        'cobb_max_deg',
    ];
    $parsed = [];
    foreach ($nums as $field) {
        $s = trim((string) ($_POST[$field] ?? ''));
        if ($s === '') {
            $parsed[$field] = null;
        } elseif (is_numeric($s)) {
            $parsed[$field] = (float) $s;
        } else {
            $errors[] = (string) __('edit.err_num', [
                'field' => (string) __('field.' . $field),
            ]);
        }
    }

    $region = strtoupper(trim((string) ($_POST['cobb_max_region'] ?? '')));
    if ($region === '') {
        $regionDb = null;
    } elseif (strlen($region) > 8) {
        $errors[] = (string) __('edit.err_region_len');
        $regionDb = null;
    } elseif (!preg_match('/^[A-Z0-9._-]+$/', $region)) {
        $errors[] = (string) __('edit.err_region_ch');
        $regionDb = null;
    } else {
        $regionDb = $region;
    }

    $vs = trim((string) ($_POST['cobb_max_vert_superior'] ?? ''));
    $vi = trim((string) ($_POST['cobb_max_vert_inferior'] ?? ''));
    $vsDb = $vs === '' ? null : (ctype_digit($vs) ? (int) $vs : null);
    $viDb = $vi === '' ? null : (ctype_digit($vi) ? (int) $vi : null);
    if ($vs !== '' && $vsDb === null) {
        $errors[] = (string) __('edit.err_vs');
    }
    if ($vi !== '' && $viDb === null) {
        $errors[] = (string) __('edit.err_vi');
    }

    if (!$errors) {
        $upd = $pdo->prepare(
            'UPDATE reports SET
                curve_type = ?,
                cobb_pt_deg = ?,
                cobb_mt_deg = ?,
                cobb_tl_deg = ?,
                cobb_thoracic_deg = ?,
                cobb_lumbar_deg = ?,
                cobb_max_region = ?,
                cobb_max_deg = ?,
                cobb_max_vert_superior = ?,
                cobb_max_vert_inferior = ?
             WHERE id = ?'
        );
        $upd->execute([
            $curveDb,
            $parsed['cobb_pt_deg'],
            $parsed['cobb_mt_deg'],
            $parsed['cobb_tl_deg'],
            $parsed['cobb_thoracic_deg'],
            $parsed['cobb_lumbar_deg'],
            $regionDb,
            $parsed['cobb_max_deg'],
            $vsDb,
            $viDb,
            $id,
        ]);
        $redir = 'report.php?id=' . $id;
        if ($safeBack !== null) {
            $redir .= '&back=' . rawurlencode($safeBack);
        }
        header('Location: ' . $redir);
        exit;
    }
}

sv_layout_start((string) __('edit.title', ['id' => (string) $id]));
$backHref = htmlspecialchars(sv_back_or('queue.php'), ENT_QUOTES, 'UTF-8');
$editPatientCrumb = $row['last_name'] . ', ' . $row['first_name'];
ob_start(); ?>
        <a href="<?= $backHref ?>" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left fa-fw"></i> <?= htmlspecialchars((string) __('common.back'), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <a href="<?= htmlspecialchars(sv_back_preserve_on('report.php?id=' . $id), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
            <i class="fa-solid fa-eye fa-fw"></i> <?= htmlspecialchars((string) __('edit.view'), ENT_QUOTES, 'UTF-8') ?>
        </a>
<?php
$topActions = ob_get_clean();
sv_topbar(
    [
        sv_crumb((string) __('nav.dashboard'), 'index.php'),
        sv_crumb((string) __('nav.patients'), 'patients.php'),
        sv_crumb($editPatientCrumb, 'patient.php?id=' . (int) $row['patient_id']),
        sv_crumb((string) __('report.title', ['id' => (string) $id]), 'report.php?id=' . (int) $id),
        sv_crumb((string) __('edit.breadcrumb'), null),
    ],
    '<i class="fa-solid fa-pen-ruler"></i> ' . htmlspecialchars((string) __('edit.heading'), ENT_QUOTES, 'UTF-8'),
    $topActions
);
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
    <?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="alert alert-info small mb-3">
    <?= (string) __('edit.info') ?>
</div>

<div class="sv-card mb-3">
    <div class="sv-card-header">
        <i class="fa-solid fa-file-medical"></i> <?= htmlspecialchars(
            (string) __('report.title', ['id' => (string) $id]),
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </div>
    <div class="sv-card-body small">
        <p class="mb-1">
            <strong><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></strong>
            · <code><?= htmlspecialchars($row['tax_code']) ?></code>
        </p>
        <p class="mb-0"><?= htmlspecialchars((string) __('edit.status_lbl'), ENT_QUOTES, 'UTF-8') ?>:
            <span class="<?= sv_status_class($row['status']) ?>">
                <?= sv_status_icon_markup($row['status']) ?>
                <span class="sv-status-text"><?= htmlspecialchars(sv_t_report_status((string) $row['status'])) ?></span>
            </span>
        </p>
    </div>
</div>

<div class="sv-card">
    <div class="sv-card-header">
        <i class="fa-solid fa-sliders"></i> <?= htmlspecialchars((string) __('edit.manual'), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div class="sv-card-body">
        <form method="post" action="report_edit_metrics.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(sv_csrf_token()) ?>">
            <input type="hidden" name="report_id" value="<?= (int) $id ?>">
            <input type="hidden" name="back" value="<?= htmlspecialchars((string) (sv_back_get_raw() ?? ''), ENT_QUOTES, 'UTF-8') ?>">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label"><?= htmlspecialchars((string) __('edit.curve'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select class="form-select" name="curve_type">
                        <?php
                        $cur = (string) ($_POST['curve_type'] ?? $row['curve_type'] ?? '');
                        ?>
                        <option value=""<?= $cur === '' ? ' selected' : '' ?>><?= htmlspecialchars(
                            (string) __('edit.curve_null'),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?></option>
                        <option value="C"<?= $cur === 'C' ? ' selected' : '' ?>><?= htmlspecialchars(
                            (string) __('edit.curve_c'),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?></option>
                        <option value="S"<?= $cur === 'S' ? ' selected' : '' ?>><?= htmlspecialchars(
                            (string) __('edit.curve_s'),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?></option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?= htmlspecialchars((string) __('edit.region'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input class="form-control" name="cobb_max_region" maxlength="8"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_max_region'] ?? $row['cobb_max_region'] ?? '')) ?>"
                           placeholder="<?= htmlspecialchars((string) __('edit.region_ph'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?= htmlspecialchars((string) __('edit.max_deg'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input class="form-control" name="cobb_max_deg" inputmode="decimal"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_max_deg'] ?? $row['cobb_max_deg'] ?? '')) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label"><?= htmlspecialchars((string) __('edit.pt'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input class="form-control" name="cobb_pt_deg" inputmode="decimal"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_pt_deg'] ?? $row['cobb_pt_deg'] ?? '')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?= htmlspecialchars((string) __('edit.mt'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input class="form-control" name="cobb_mt_deg" inputmode="decimal"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_mt_deg'] ?? $row['cobb_mt_deg'] ?? '')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?= htmlspecialchars((string) __('edit.tl'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input class="form-control" name="cobb_tl_deg" inputmode="decimal"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_tl_deg'] ?? $row['cobb_tl_deg'] ?? '')) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label"><?= htmlspecialchars((string) __('edit.thor'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input class="form-control" name="cobb_thoracic_deg" inputmode="decimal"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_thoracic_deg'] ?? $row['cobb_thoracic_deg'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= htmlspecialchars((string) __('edit.lum'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input class="form-control" name="cobb_lumbar_deg" inputmode="decimal"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_lumbar_deg'] ?? $row['cobb_lumbar_deg'] ?? '')) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label"><?= htmlspecialchars((string) __('edit.vsup'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input class="form-control" name="cobb_max_vert_superior" inputmode="numeric"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_max_vert_superior'] ?? $row['cobb_max_vert_superior'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label"><?= htmlspecialchars((string) __('edit.vinf'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input class="form-control" name="cobb_max_vert_inferior" inputmode="numeric"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_max_vert_inferior'] ?? $row['cobb_max_vert_inferior'] ?? '')) ?>">
                </div>
            </div>

            <div class="mt-3 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk fa-fw"></i> <?= htmlspecialchars(
                        (string) __('edit.save'),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </button>
                <a href="<?= $backHref ?>" class="btn btn-secondary"><?= htmlspecialchars(
                    (string) __('edit.cancel'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></a>
            </div>
        </form>
    </div>
</div>
<?php sv_layout_end(); ?>

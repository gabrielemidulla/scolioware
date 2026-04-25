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
    echo 'Missing report id';
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
    echo 'Report not found';
    exit;
}

$status = (string) $row['status'];
$editable = $status === 'failed' || $status === 'completed';
if (!$editable) {
    sv_layout_start('Cannot revise report');
    $backEarly = htmlspecialchars(sv_back_or('queue.php'), ENT_QUOTES, 'UTF-8');
    sv_topbar(
        [
            sv_crumb('Dashboard', 'index.php'),
            sv_crumb('Report #' . $id, null),
        ],
        '<i class="fa-solid fa-triangle-exclamation"></i> Cannot revise report',
        '<a href="' . $backEarly . '" class="btn btn-secondary">Back</a>'
    );
    ?>
    <div class="alert alert-warning">
        Cobb angles and curve type can only be revised for reports that are
        <strong>completed</strong> (to correct wrong automatic measurements) or <strong>failed</strong> (automatic measurement error).
        This report is <code><?= htmlspecialchars($status) ?></code>.
    </div>
    <a href="<?= htmlspecialchars(sv_back_or('queue.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">Back</a>
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
        $errors[] = 'Curve type must be empty, C, or S.';
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
            $errors[] = 'Invalid number for ' . str_replace('_', ' ', $field) . '.';
        }
    }

    $region = strtoupper(trim((string) ($_POST['cobb_max_region'] ?? '')));
    if ($region === '') {
        $regionDb = null;
    } elseif (strlen($region) > 8) {
        $errors[] = 'Max Cobb region must be at most 8 characters.';
        $regionDb = null;
    } elseif (!preg_match('/^[A-Z0-9._-]+$/', $region)) {
        $errors[] = 'Max Cobb region has invalid characters.';
        $regionDb = null;
    } else {
        $regionDb = $region;
    }

    $vs = trim((string) ($_POST['cobb_max_vert_superior'] ?? ''));
    $vi = trim((string) ($_POST['cobb_max_vert_inferior'] ?? ''));
    $vsDb = $vs === '' ? null : (ctype_digit($vs) ? (int) $vs : null);
    $viDb = $vi === '' ? null : (ctype_digit($vi) ? (int) $vi : null);
    if ($vs !== '' && $vsDb === null) {
        $errors[] = 'Superior vertebra index must be a whole number or empty.';
    }
    if ($vi !== '' && $viDb === null) {
        $errors[] = 'Inferior vertebra index must be a whole number or empty.';
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

sv_layout_start('Revise metrics · Report #' . $id);
$backHref = htmlspecialchars(sv_back_or('queue.php'), ENT_QUOTES, 'UTF-8');
$editPatientCrumb = $row['last_name'] . ', ' . $row['first_name'];
ob_start(); ?>
        <a href="<?= $backHref ?>" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left fa-fw"></i> Back
        </a>
        <a href="<?= htmlspecialchars(sv_back_preserve_on('report.php?id=' . $id), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
            <i class="fa-solid fa-eye fa-fw"></i> Report view
        </a>
<?php
$topActions = ob_get_clean();
sv_topbar(
    [
        sv_crumb('Dashboard', 'index.php'),
        sv_crumb('Patients', 'patients.php'),
        sv_crumb($editPatientCrumb, 'patient.php?id=' . (int) $row['patient_id']),
        sv_crumb('Report #' . (int) $id, 'report.php?id=' . (int) $id),
        sv_crumb('Revise metrics', null),
    ],
    '<i class="fa-solid fa-pen-ruler"></i> Revise Cobb &amp; curve',
    $topActions
);
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
    <?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="alert alert-info small mb-3">
    Use this form when automatic measurements <strong>failed</strong> or produced <strong>incorrect angles</strong>.
    Values here are stored on the report and appear in the dashboard and in generated PDFs.
</div>

<div class="sv-card mb-3">
    <div class="sv-card-header">
        <i class="fa-solid fa-file-medical"></i> Report #<?= (int) $id ?>
    </div>
    <div class="sv-card-body small">
        <p class="mb-1">
            <strong><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></strong>
            · <code><?= htmlspecialchars($row['tax_code']) ?></code>
        </p>
        <p class="mb-0">Status:
            <span class="<?= sv_status_class($row['status']) ?>">
                <?= sv_status_icon_markup($row['status']) ?>
                <span class="sv-status-text"><?= htmlspecialchars($row['status']) ?></span>
            </span>
        </p>
    </div>
</div>

<div class="sv-card">
    <div class="sv-card-header">
        <i class="fa-solid fa-sliders"></i> Manual values
    </div>
    <div class="sv-card-body">
        <form method="post" action="report_edit_metrics.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(sv_csrf_token()) ?>">
            <input type="hidden" name="report_id" value="<?= (int) $id ?>">
            <input type="hidden" name="back" value="<?= htmlspecialchars((string) (sv_back_get_raw() ?? ''), ENT_QUOTES, 'UTF-8') ?>">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Curve type</label>
                    <select class="form-select" name="curve_type">
                        <?php
                        $cur = (string) ($_POST['curve_type'] ?? $row['curve_type'] ?? '');
                        ?>
                        <option value=""<?= $cur === '' ? ' selected' : '' ?>>Not classified (NULL)</option>
                        <option value="C"<?= $cur === 'C' ? ' selected' : '' ?>>C — single curve</option>
                        <option value="S"<?= $cur === 'S' ? ' selected' : '' ?>>S — double curve</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Max Cobb region</label>
                    <input class="form-control" name="cobb_max_region" maxlength="8"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_max_region'] ?? $row['cobb_max_region'] ?? '')) ?>"
                           placeholder="e.g. THORACIC">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Max Cobb (°)</label>
                    <input class="form-control" name="cobb_max_deg" inputmode="decimal"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_max_deg'] ?? $row['cobb_max_deg'] ?? '')) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">PT (°)</label>
                    <input class="form-control" name="cobb_pt_deg" inputmode="decimal"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_pt_deg'] ?? $row['cobb_pt_deg'] ?? '')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">MT (°)</label>
                    <input class="form-control" name="cobb_mt_deg" inputmode="decimal"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_mt_deg'] ?? $row['cobb_mt_deg'] ?? '')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">TL (°)</label>
                    <input class="form-control" name="cobb_tl_deg" inputmode="decimal"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_tl_deg'] ?? $row['cobb_tl_deg'] ?? '')) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Thoracic (max PT/MT) (°)</label>
                    <input class="form-control" name="cobb_thoracic_deg" inputmode="decimal"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_thoracic_deg'] ?? $row['cobb_thoracic_deg'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Lumbar / TL (°)</label>
                    <input class="form-control" name="cobb_lumbar_deg" inputmode="decimal"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_lumbar_deg'] ?? $row['cobb_lumbar_deg'] ?? '')) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Max Cobb superior vertebra (1-based)</label>
                    <input class="form-control" name="cobb_max_vert_superior" inputmode="numeric"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_max_vert_superior'] ?? $row['cobb_max_vert_superior'] ?? '')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Max Cobb inferior vertebra (1-based)</label>
                    <input class="form-control" name="cobb_max_vert_inferior" inputmode="numeric"
                           value="<?= htmlspecialchars((string) ($_POST['cobb_max_vert_inferior'] ?? $row['cobb_max_vert_inferior'] ?? '')) ?>">
                </div>
            </div>

            <div class="mt-3 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk fa-fw"></i> Save metrics
                </button>
                <a href="<?= $backHref ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php sv_layout_end(); ?>

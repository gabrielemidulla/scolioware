<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/back.php';
require __DIR__ . '/inc/pagination.php';
require_once __DIR__ . '/inc/phi_log.php';

/**
 * Match inference `refresh_patient_last_vitals`: newest report with both vitals sets patients.last_*.
 */
function sv_refresh_patient_last_vitals(PDO $pdo, int $patientId): void
{
    $stmt = $pdo->prepare(
        'SELECT height_cm, weight_kg FROM reports
         WHERE patient_id = ? AND height_cm IS NOT NULL AND weight_kg IS NOT NULL
         ORDER BY created_at DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([$patientId]);
    $r = $stmt->fetch();
    if ($r) {
        $u = $pdo->prepare('UPDATE patients SET last_height_cm = ?, last_weight_kg = ? WHERE id = ?');
        $u->execute([(float) $r['height_cm'], (float) $r['weight_kg'], $patientId]);
    } else {
        $u = $pdo->prepare('UPDATE patients SET last_height_cm = NULL, last_weight_kg = NULL WHERE id = ?');
        $u->execute([$patientId]);
    }
}

sv_require_auth();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo htmlspecialchars((string) __('report.err_id'), ENT_QUOTES, 'UTF-8');
    exit;
}

$vitalsErrors = [];
$postedBack = isset($_POST['back']) ? (string) $_POST['back'] : null;
$safeBack = sv_back_validate($postedBack) ?? sv_back_validate(sv_back_get_raw());

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report_vitals'])) {
    sv_csrf_check();
    if ((int) ($_POST['report_id'] ?? 0) !== $id) {
        http_response_code(400);
        echo htmlspecialchars((string) __('report.err_invalid_id'), ENT_QUOTES, 'UTF-8');
        exit;
    }

    $hRaw = trim((string) ($_POST['height_cm'] ?? ''));
    $wRaw = trim((string) ($_POST['weight_kg'] ?? ''));
    $hDb = null;
    $wDb = null;

    if ($hRaw !== '') {
        $hs = str_replace(',', '.', $hRaw);
        if (!preg_match('/^\d+(\.\d+)?$/', $hs)) {
            $vitalsErrors[] = (string) __('report.vitals_error_h');
        } else {
            $hv = (float) $hs;
            if ($hv < 50.0 || $hv > 250.0) {
                $vitalsErrors[] = (string) __('report.vitals_error_h_range');
            } else {
                $hDb = round($hv, 2);
            }
        }
    }
    if ($wRaw !== '') {
        $ws = str_replace(',', '.', $wRaw);
        if (!preg_match('/^\d+(\.\d+)?$/', $ws)) {
            $vitalsErrors[] = (string) __('report.vitals_error_w');
        } else {
            $wv = (float) $ws;
            if ($wv < 10.0 || $wv > 350.0) {
                $vitalsErrors[] = (string) __('report.vitals_error_w_range');
            } else {
                $wDb = round($wv, 2);
            }
        }
    }

    if ($vitalsErrors === []) {
        $pdo = db();
        $chk = $pdo->prepare('SELECT id, patient_id FROM reports WHERE id = ? LIMIT 1');
        $chk->execute([$id]);
        $rep = $chk->fetch();
        if (!$rep) {
            http_response_code(404);
            echo htmlspecialchars((string) __('report.err_nf'), ENT_QUOTES, 'UTF-8');
            exit;
        }
        $upd = $pdo->prepare('UPDATE reports SET height_cm = ?, weight_kg = ? WHERE id = ?');
        $upd->execute([$hDb, $wDb, $id]);
        sv_refresh_patient_last_vitals($pdo, (int) $rep['patient_id']);

        $redir = 'report.php?id=' . $id . '&vitals=ok';
        if ($safeBack !== null) {
            $redir .= '&back=' . rawurlencode($safeBack);
        }
        header('Location: ' . $redir);
        exit;
    }
}

$stmt = db()->prepare(
    'SELECT r.*, p.first_name, p.last_name, p.tax_code
     FROM reports r JOIN patients p ON p.id = r.patient_id WHERE r.id = ?'
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo htmlspecialchars((string) __('report.err_nf'), ENT_QUOTES, 'UTF-8');
    exit;
}

sv_phi_log('view_report', (int) $row['patient_id'], (int) $id, null);

$canReviseMetrics = $row['status'] === 'failed' || $row['status'] === 'completed';
$hasBack = sv_back_validate(sv_back_get_raw()) !== null;

$pdfPerPage = 5;
$pdfCountStmt = db()->prepare(
    'SELECT COUNT(*) FROM pdf_reports WHERE report_id = ? AND deleted_at IS NULL'
);
$pdfCountStmt->execute([$id]);
$pdfTotalCount = (int) $pdfCountStmt->fetchColumn();
$pdfTotalPages = max(1, (int) ceil($pdfTotalCount / $pdfPerPage));
$pdfPage = sv_get_int('pdf_page', 1, 1);
if ($pdfPage > $pdfTotalPages) {
    $pdfPage = $pdfTotalPages;
}
$pdfOffset = ($pdfPage - 1) * $pdfPerPage;

$pdfStmt = db()->prepare(
    'SELECT pr.id, pr.title, pr.notes, pr.created_at, pr.physician_username,
            ph.display_name AS physician_display_name
     FROM pdf_reports pr
     LEFT JOIN physicians ph ON ph.id = pr.physician_id AND ph.deleted_at IS NULL
     WHERE pr.report_id = ? AND pr.deleted_at IS NULL
     ORDER BY pr.created_at DESC, pr.id DESC
     LIMIT ' . (int) $pdfPerPage . ' OFFSET ' . (int) $pdfOffset
);
$pdfStmt->execute([$id]);
$pdfReports = $pdfStmt->fetchAll();

$rvH = $row['height_cm'] ?? null;
$rvW = $row['weight_kg'] ?? null;
$showVitals = $rvH !== null && $rvH !== '' && is_numeric($rvH) && $rvW !== null && $rvW !== '' && is_numeric($rvW);
$uCm = (string) __('patient.unit_cm');
$uKg = (string) __('patient.unit_kg');
$vitalsText = $showVitals
    ? number_format((float) $rvH, 2) . $uCm . ', ' . number_format((float) $rvW, 2) . $uKg
    : (string) __('common.dash');
$pdfCountLine = '(' . (int) $pdfTotalCount . ' · ' . (string) __('patient.reports_sub');
if ($pdfTotalPages > 1) {
    $pdfCountLine .= ' · ' . (string) __('report.pdf_sub_page', ['cur' => (int) $pdfPage, 'max' => (int) $pdfTotalPages]);
}
$pdfCountLine .= ')';

$vitalsFormH = '';
$vitalsFormW = '';
if ($vitalsErrors !== []) {
    $vitalsFormH = (string) ($_POST['height_cm'] ?? '');
    $vitalsFormW = (string) ($_POST['weight_kg'] ?? '');
} else {
    $vitalsFormH = ($rvH !== null && $rvH !== '' && is_numeric($rvH)) ? sprintf('%.2f', (float) $rvH) : '';
    $vitalsFormW = ($rvW !== null && $rvW !== '' && is_numeric($rvW)) ? sprintf('%.2f', (float) $rvW) : '';
}

$vitalsSavedOk = isset($_GET['vitals']) && (string) $_GET['vitals'] === 'ok';
$landmarksRecomputeOk = isset($_GET['landmarks']) && (string) $_GET['landmarks'] === 'ok';

sv_layout_start((string) __('report.title', ['id' => (string) (int) $row['id']]));
$patientBreadcrumbLabel = $row['last_name'] . ', ' . $row['first_name'];
ob_start();
if ($hasBack): ?>
            <a href="<?= htmlspecialchars(sv_back_or('queue.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left fa-fw"></i> <?= htmlspecialchars((string) __('common.back'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php else: ?>
            <a href="queue.php" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left fa-fw"></i> <?= htmlspecialchars((string) __('common.queue'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endif;
$topActions = ob_get_clean();
$repTitle = (string) __('report.title', ['id' => (string) (int) $row['id']]);
sv_topbar(
    [
        sv_crumb((string) __('nav.dashboard'), 'index.php'),
        sv_crumb((string) __('nav.patients'), 'patients.php'),
        sv_crumb($patientBreadcrumbLabel, 'patient.php?id=' . (int) $row['patient_id']),
        sv_crumb($repTitle, null),
    ],
    '<i class="fa-solid fa-file-medical"></i> ' . htmlspecialchars($repTitle, ENT_QUOTES, 'UTF-8'),
    $topActions
);
?>

<?php if ($vitalsErrors !== []): ?>
    <div class="alert alert-danger mb-3" role="alert">
        <strong><?= htmlspecialchars((string) __('report.cannot_save_vitals'), ENT_QUOTES, 'UTF-8') ?></strong>
        <ul class="mb-0 mt-2"><?php foreach ($vitalsErrors as $msg): ?>
            <li><?= htmlspecialchars((string) $msg, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?></ul>
    </div>
<?php endif; ?>
<?php if ($vitalsSavedOk): ?>
    <div class="alert alert-success mb-3" role="alert">
        <strong><?= htmlspecialchars((string) __('report.vitals_saved'), ENT_QUOTES, 'UTF-8') ?></strong> <?= htmlspecialchars(
            (string) __('report.vitals_saved_desc'),
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </div>
<?php endif; ?>
<?php if ($landmarksRecomputeOk): ?>
    <div class="alert alert-success mb-3" role="alert">
        <?= htmlspecialchars((string) __('report.landmarks_recompute_ok'), ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="sv-card">
    <div class="sv-card-body">
        <p class="mb-1 d-flex flex-wrap align-items-center gap-2">
            <span>
                <i class="fa-solid fa-user-injured text-muted"></i>
                <strong><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></strong>
                &middot; <code><?= htmlspecialchars($row['tax_code']) ?></code>
            </span>
            <a href="<?= htmlspecialchars(sv_append_back('patient.php?id=' . (int) $row['patient_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-secondary">
                <i class="fa-solid fa-user-injured fa-fw"></i> <?= htmlspecialchars(
                    (string) __('report.patient_btn'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </a>
        </p>
        <p class="mb-0">
            <?= htmlspecialchars((string) __('report.status'), ENT_QUOTES, 'UTF-8') ?>:
            <span id="status" class="<?= sv_status_class($row['status']) ?>"><?= sv_status_icon_markup($row['status']) ?><span class="sv-status-text"><?= htmlspecialchars(
                sv_t_report_status((string) $row['status'])
            ) ?></span></span>
            &nbsp; <?= htmlspecialchars((string) __('report.curve'), ENT_QUOTES, 'UTF-8') ?>: <span id="curve" class="fw-semibold"><?php
                $ct0 = (string) ($row['curve_type'] ?? '');
                echo $ct0 === ''
                    ? htmlspecialchars((string) __('common.dash'), ENT_QUOTES, 'UTF-8')
                    : htmlspecialchars(sv_t_curve_filter($ct0), ENT_QUOTES, 'UTF-8');
            ?></span>
            <span class="text-muted" id="vitals_wrap"<?= $showVitals ? '' : ' style="display:none;"' ?>> &middot; <?= htmlspecialchars(
                (string) __('report.exam'),
                ENT_QUOTES,
                'UTF-8'
            ) ?>: <strong id="vitals"><?= htmlspecialchars($vitalsText) ?></strong></span>
        </p>
    </div>
</div>

<div class="sv-report-main-grid">
    <div class="sv-report-main-left d-flex flex-column min-h-0 gap-3">
        <div class="sv-card flex-shrink-0">
            <div class="sv-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span><i class="fa-solid fa-ruler-combined"></i> <?= htmlspecialchars(
                    (string) __('report.measurements'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></span>
                <?php if ($canReviseMetrics): ?>
                    <a href="<?= htmlspecialchars(sv_append_back('report_edit_metrics.php?id=' . (int) $row['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary">
                        <i class="fa-solid fa-pen fa-fw"></i> <?= htmlspecialchars(
                            (string) __('report.edit'),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </a>
                <?php endif; ?>
            </div>
            <div class="sv-card-body p-0">
                <table class="table mb-0" id="cobb_summary">
                    <tbody>
                        <tr><th><?= htmlspecialchars((string) __('report.cobb_pt'), ENT_QUOTES, 'UTF-8') ?></th><td id="cobb_pt">—</td></tr>
                        <tr><th><?= htmlspecialchars((string) __('report.cobb_mt'), ENT_QUOTES, 'UTF-8') ?></th><td id="cobb_mt">—</td></tr>
                        <tr><th><?= htmlspecialchars((string) __('report.cobb_tl'), ENT_QUOTES, 'UTF-8') ?></th><td id="cobb_tl">—</td></tr>
                        <tr><th><?= htmlspecialchars((string) __('report.cobb_th'), ENT_QUOTES, 'UTF-8') ?></th><td id="cobb_thoracic">—</td></tr>
                        <tr><th><?= htmlspecialchars((string) __('report.cobb_lum'), ENT_QUOTES, 'UTF-8') ?></th><td id="cobb_lumbar">—</td></tr>
                        <tr><th><?= htmlspecialchars((string) __('report.cobb_max'), ENT_QUOTES, 'UTF-8') ?></th><td id="cobb_max">—</td></tr>
                        <tr><th><?= htmlspecialchars((string) __('report.cobb_verts'), ENT_QUOTES, 'UTF-8') ?></th><td id="cobb_verts">—</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-stretch flex-grow-1 min-h-0 sv-report-vitals-pdf-row">
            <div class="d-flex align-items-stretch flex-shrink-0 sv-report-vitals-pdf-col">
                <div class="sv-card sv-report-vitals-card w-100 d-flex flex-column">
                    <div class="sv-card-header">
                        <i class="fa-solid fa-ruler-vertical"></i> <?= htmlspecialchars(
                            (string) __('report.vitals'),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </div>
                    <div class="sv-card-body d-flex flex-column flex-grow-1">
                        <form class="d-flex flex-column flex-grow-1" method="post" action="<?= htmlspecialchars(sv_back_preserve_on('report.php?id=' . (int) $id), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(sv_csrf_token()) ?>">
                            <input type="hidden" name="report_id" value="<?= (int) $id ?>">
                            <input type="hidden" name="back" value="<?= htmlspecialchars((string) (sv_back_get_raw() ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="mb-3">
                                <label class="form-label" for="height_cm"><?= htmlspecialchars(
                                    (string) __('report.h_cm'),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?></label>
                                <input class="form-control" id="height_cm" name="height_cm" inputmode="decimal" autocomplete="off"
                                       placeholder="<?= htmlspecialchars((string) __('report.h_ph'), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($vitalsFormH, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="weight_kg"><?= htmlspecialchars(
                                    (string) __('report.w_kg'),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?></label>
                                <input class="form-control" id="weight_kg" name="weight_kg" inputmode="decimal" autocomplete="off"
                                       placeholder="<?= htmlspecialchars((string) __('report.w_ph'), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($vitalsFormW, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="mt-auto pt-1">
                                <button type="submit" name="save_report_vitals" value="1" class="btn btn-primary">
                                    <i class="fa-solid fa-floppy-disk fa-fw"></i> <?= htmlspecialchars(
                                        (string) __('report.save'),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="flex-grow-1 min-w-0 d-flex flex-column">
                <div class="sv-card sv-report-pdf-card h-100 d-flex flex-column flex-grow-1 min-h-0" id="pdf-reports">
                    <div class="sv-card-header d-flex flex-wrap align-items-center justify-content-between gap-2 flex-shrink-0">
                        <span>
                            <i class="fa-solid fa-file-pdf"></i> <?= htmlspecialchars(
                                (string) __('report.pdf_section'),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                            <span class="text-muted fw-normal"><?= htmlspecialchars($pdfCountLine, ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <a href="<?= htmlspecialchars(sv_append_back('new_pdf.php?report_id=' . (int) $row['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary">
                            <i class="fa-solid fa-file-pdf fa-fw"></i> <?= htmlspecialchars(
                                (string) __('report.create_pdf'),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </a>
                    </div>
                    <div class="sv-card-body p-0 d-flex flex-column flex-grow-1 min-h-0">
                        <div class="table-responsive sv-report-pdf-table-wrap flex-grow-1 min-h-0">
                            <table class="table table-striped mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th><?= htmlspecialchars((string) __('report.col_pdf'), ENT_QUOTES, 'UTF-8') ?></th>
                                        <th><?= htmlspecialchars((string) __('report.col_title'), ENT_QUOTES, 'UTF-8') ?></th>
                                        <th><?= htmlspecialchars((string) __('report.col_physician'), ENT_QUOTES, 'UTF-8') ?></th>
                                        <th><?= htmlspecialchars((string) __('report.col_created'), ENT_QUOTES, 'UTF-8') ?></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $pdfDash = '<span class="text-muted">' . htmlspecialchars(
                                    (string) __('common.dash'),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) . '</span>';
                                foreach ($pdfReports as $pr):
                                ?>
                                    <tr id="pdf-<?= (int) $pr['id'] ?>">
                                        <td><strong>#<?= (int) $pr['id'] ?></strong></td>
                                        <td><?= htmlspecialchars((string) ($pr['title'] ?? '')) !== '' ? htmlspecialchars(
                                            (string) ($pr['title'] ?? '')
                                        ) : $pdfDash ?></td>
                                        <td><?php
                                            $dn = trim((string) ($pr['physician_display_name'] ?? ''));
                                            $un = (string) ($pr['physician_username'] ?? '');
                                            if ($dn !== '') {
                                                echo htmlspecialchars($dn);
                                            } elseif ($un !== '') {
                                                echo '<code>' . htmlspecialchars($un) . '</code>';
                                            } else {
                                                echo $pdfDash;
                                            }
                                        ?></td>
                                        <td><span class="text-muted small"><?= htmlspecialchars((string) $pr['created_at']) ?></span></td>
                                        <td class="text-end">
                                            <a href="pdf.php?id=<?= (int) $pr['id'] ?>" target="_blank" rel="noopener"
                                               class="btn btn-primary btn-sm"
                                               aria-label="<?= htmlspecialchars((string) __('report.aria_open_pdf'), ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid fa-download fa-fw"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$pdfReports): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">
                                        <?= (string) __('report.pdf_empty') ?>
                                    </td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="sv-report-pdf-footer border-top flex-shrink-0 px-2 py-2 d-flex align-items-center justify-content-center">
                            <?php sv_render_pagination_for('report.php', 'pdf_page', $pdfPage, $pdfTotalPages); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="sv-report-main-images d-flex flex-column min-h-0">
        <div class="sv-card sv-report-images-card flex-grow-1 d-flex flex-column min-h-0">
            <div class="sv-card-header flex-shrink-0 d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span>
                    <i class="fa-solid fa-images"></i> <?= htmlspecialchars((string) __('report.images'), ENT_QUOTES, 'UTF-8') ?>
                    <span class="text-muted fw-normal small"><?= htmlspecialchars(
                        (string) __('report.img_hint'),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?></span>
                </span>
                <button type="button" id="open_landmark_editor" class="btn btn-sm btn-primary" style="display:none;"
                        data-bs-toggle="modal" data-bs-target="#svLandmarkEditor">
                    <i class="fa-solid fa-compass-drafting fa-fw"></i> <?= htmlspecialchars(
                        (string) __('lmk.open_btn'),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </button>
            </div>
            <div class="sv-card-body flex-grow-1 d-flex flex-column min-h-0">
                <div class="row g-2 sv-report-image-row flex-grow-1 align-items-stretch min-h-0">
                    <div class="col-6 d-flex min-h-0">
                        <div class="sv-report-thumb-wrap flex-grow-1 w-100" id="img_orig_wrap" style="display:none;" role="button" tabindex="0" aria-label="<?= htmlspecialchars(
                            (string) __('report.aria_orig'),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>">
                            <img id="img_orig" class="sv-report-thumb" alt="<?= htmlspecialchars(
                                (string) __('report.label_orig'),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>">
                            <span class="sv-report-thumb-label"><?= htmlspecialchars(
                                (string) __('report.label_orig'),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></span>
                        </div>
                    </div>
                    <div class="col-6 d-flex min-h-0">
                        <div class="sv-report-thumb-wrap flex-grow-1 w-100" id="img_comp_wrap" style="display:none;" role="button" tabindex="0" aria-label="<?= htmlspecialchars(
                            (string) __('report.aria_comp'),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>">
                            <img id="img_comp" class="sv-report-thumb" alt="<?= htmlspecialchars(
                                (string) __('report.label_overlay'),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>">
                            <span class="sv-report-thumb-label"><?= htmlspecialchars(
                                (string) __('report.label_overlay'),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></span>
                        </div>
                    </div>
                </div>
                <p id="img_hint" class="text-muted mb-0 mt-2 small"></p>
                <p id="poll_error" class="text-danger mb-0 mt-2 small" style="display:none;"></p>
            </div>
        </div>
    </div>
</div><!-- /.sv-report-main-grid -->

<div class="sv-card mt-3" id="llm_card" style="display:none;">
    <div class="sv-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span>
            <i class="fa-solid fa-wand-magic-sparkles"></i> <?= htmlspecialchars(
                (string) __('llm.title'),
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </span>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span id="llm_meta" class="text-muted small"></span>
            <button type="button" id="llm_generate" class="btn btn-sm btn-primary">
                <i class="fa-solid fa-wand-magic-sparkles fa-fw"></i>
                <span id="llm_btn_label"><?= htmlspecialchars((string) __('llm.generate'), ENT_QUOTES, 'UTF-8') ?></span>
            </button>
        </div>
    </div>
    <div class="sv-card-body">
        <p class="small text-muted mb-2"><?= (string) __('llm.help') ?></p>
        <div id="llm_status" class="small text-muted mb-2" style="display:none;"></div>
        <div id="llm_error" class="alert alert-danger small mb-2" role="alert" style="display:none;"></div>
        <div id="llm_empty" class="text-muted small fst-italic"><?= htmlspecialchars(
            (string) __('llm.empty'),
            ENT_QUOTES,
            'UTF-8'
        ) ?></div>
        <div id="llm_draft" class="sv-llm-draft" style="display:none;"></div>
    </div>
</div>

<div class="modal fade" id="svLandmarkEditor" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content bg-dark text-white">
            <div class="modal-header border-secondary py-2">
                <h5 class="modal-title">
                    <i class="fa-solid fa-compass-drafting"></i>
                    <?= htmlspecialchars((string) __('lmk.title'), ENT_QUOTES, 'UTF-8') ?>
                </h5>
                <div class="ms-auto d-flex flex-wrap align-items-center gap-2">
                    <span id="lmk_count" class="text-muted small"></span>
                    <button type="button" id="lmk_add" class="btn btn-sm btn-success">
                        <i class="fa-solid fa-square-plus fa-fw"></i> <?= htmlspecialchars((string) __('lmk.add'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="button" id="lmk_remove" class="btn btn-sm btn-outline-light" disabled>
                        <i class="fa-solid fa-trash-can fa-fw"></i> <?= htmlspecialchars((string) __('lmk.remove'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="button" id="lmk_reset" class="btn btn-sm btn-outline-light">
                        <i class="fa-solid fa-rotate-left fa-fw"></i> <?= htmlspecialchars((string) __('lmk.reset'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="button" id="lmk_save" class="btn btn-sm btn-primary">
                        <i class="fa-solid fa-floppy-disk fa-fw"></i> <?= htmlspecialchars((string) __('lmk.save'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars((string) __('report.aria_close'), ENT_QUOTES, 'UTF-8') ?>"></button>
                </div>
            </div>
            <div class="modal-body p-0 d-flex flex-column">
                <div id="lmk_msg" class="alert alert-warning rounded-0 mb-0 py-2 small" role="alert" style="display:none;"></div>
                <div id="lmk_help" class="px-3 py-2 small text-muted border-bottom border-secondary">
                    <?= (string) __('lmk.help') ?>
                </div>
                <div id="lmk_stage_wrap" class="flex-grow-1 d-flex align-items-center justify-content-center sv-lmk-stage-wrap">
                    <div id="lmk_stage" class="sv-lmk-stage"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="svImgLightbox" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-fullscreen-lg-down">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary py-2">
                <h5 class="modal-title" id="svImgLightboxTitle"><?= htmlspecialchars(
                    (string) __('report.lightbox'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(
                    (string) __('report.aria_close'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"></button>
            </div>
            <div class="modal-body p-2 text-center d-flex align-items-center justify-content-center" style="min-height: 50vh;">
                <img id="svImgLightboxImg" class="img-fluid rounded" alt="" style="max-height: 85vh; width: auto;">
            </div>
        </div>
    </div>
</div>

<?php
$reportI18n = [
    'dash' => (string) __('common.dash'),
    'uCm' => (string) __('patient.unit_cm'),
    'uKg' => (string) __('patient.unit_kg'),
    'status' => [
        'pending' => (string) __('status.pending'),
        'processing' => (string) __('status.processing'),
        'completed' => (string) __('status.completed'),
        'failed' => (string) __('status.failed'),
    ],
    'curve' => [
        'C' => (string) __('curve.opt_c'),
        'S' => (string) __('curve.opt_s'),
    ],
    'js_orig' => (string) __('report.js_orig'),
    'js_overlay' => (string) __('report.js_overlay'),
    'js_waiting' => (string) __('report.js_waiting'),
    'js_failed' => (string) __('report.js_failed'),
    'js_poll_err' => (string) __('report.js_poll_err'),
    'lmk_count' => (string) __('lmk.count'),
    'lmk_min' => (string) __('lmk.err_min'),
    'lmk_no_orig' => (string) __('lmk.err_no_orig'),
    'lmk_load_err' => (string) __('lmk.err_load'),
    'lmk_load_konva_err' => (string) __('lmk.err_konva'),
    'lmk_saving' => (string) __('lmk.saving'),
    'lmk_save_ok' => (string) __('lmk.save_ok'),
    'lmk_confirm_close' => (string) __('lmk.confirm_close'),
    'lmk_confirm_reset' => (string) __('lmk.confirm_reset'),
    'llm_generate' => (string) __('llm.generate'),
    'llm_regenerate' => (string) __('llm.regenerate'),
    'llm_generating' => (string) __('llm.generating'),
    'llm_queued' => (string) __('llm.queued'),
    'llm_running' => (string) __('llm.running'),
    'llm_load_err' => (string) __('llm.err_load'),
    'llm_gen_err' => (string) __('llm.err_gen'),
    'llm_meta' => (string) __('llm.meta'),
    'llm_confirm_regen' => (string) __('llm.confirm_regen'),
];
?>
<script>
    var I18N = <?= json_encode($reportI18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
    var reportId = <?= json_encode($id) ?>;
    var CSRF_TOKEN = <?= json_encode(sv_csrf_token()) ?>;
    var lastReport = null;
    var imageVersion = 0;
    var forceImageRefresh = false;
    var STATUS_CLASS = {
        pending: 'sv-status sv-status-pending',
        processing: 'sv-status sv-status-processing',
        completed: 'sv-status sv-status-completed',
        failed: 'sv-status sv-status-failed'
    };
    var STATUS_ICONS = {
        pending: 'fa-clock',
        processing: 'fa-gear',
        completed: 'fa-circle-check',
        failed: 'fa-triangle-exclamation'
    };
    var imgModal = null;
    document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('svImgLightbox');
        if (el && typeof bootstrap !== 'undefined') {
            imgModal = new bootstrap.Modal(el);
        }
    });
    function openImageLightbox(src, title) {
        document.getElementById('svImgLightboxImg').src = src;
        document.getElementById('svImgLightboxImg').alt = title;
        document.getElementById('svImgLightboxTitle').textContent = title;
        if (imgModal) {
            imgModal.show();
        }
    }
    function wireThumb(wrapId, imgId, title) {
        var wrap = document.getElementById(wrapId);
        var img = document.getElementById(imgId);
        if (!wrap || !img) return;
        function go() {
            if (img.src) openImageLightbox(img.src, title);
        }
        wrap.addEventListener('click', go);
        wrap.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                go();
            }
        });
    }
    wireThumb('img_orig_wrap', 'img_orig', I18N.js_orig);
    wireThumb('img_comp_wrap', 'img_comp', I18N.js_overlay);

    function setStatusBadge(el, status) {
        var s = status || '';
        el.className = STATUS_CLASS[s] || 'sv-status sv-status-unknown';
        el.replaceChildren();
        var ic = STATUS_ICONS[s] || 'fa-circle-question';
        var icon = document.createElement('i');
        icon.className = 'fa-solid ' + ic + ' sv-status-icon';
        icon.setAttribute('aria-hidden', 'true');
        var text = document.createElement('span');
        text.className = 'sv-status-text';
        text.textContent = (I18N.status && I18N.status[s]) ? I18N.status[s] : s;
        el.appendChild(icon);
        el.appendChild(text);
    }
    function fmtDeg(x) {
        if (x === null || x === undefined || x === '') return I18N.dash;
        var n = Number(x);
        return isNaN(n) ? I18N.dash : n.toFixed(2);
    }
    function fillCobbSummary(j) {
        document.getElementById('cobb_pt').textContent = fmtDeg(j.cobb_pt_deg);
        document.getElementById('cobb_mt').textContent = fmtDeg(j.cobb_mt_deg);
        document.getElementById('cobb_tl').textContent = fmtDeg(j.cobb_tl_deg);
        document.getElementById('cobb_thoracic').textContent = fmtDeg(j.cobb_thoracic_deg);
        document.getElementById('cobb_lumbar').textContent = fmtDeg(j.cobb_lumbar_deg);
        var maxLine = I18N.dash;
        if (j.cobb_max_deg != null && j.cobb_max_deg !== '') {
            var reg = (j.cobb_max_region || '').toString().toUpperCase();
            maxLine = reg + ' ' + fmtDeg(j.cobb_max_deg) + '°';
        }
        document.getElementById('cobb_max').textContent = maxLine;
        var vs = j.cobb_max_vert_superior, vi = j.cobb_max_vert_inferior;
        document.getElementById('cobb_verts').textContent =
            (vs != null && vi != null) ? (String(vs) + ' – ' + String(vi)) : I18N.dash;
    }
    function setVitals(j) {
        var wrap = document.getElementById('vitals_wrap');
        var el = document.getElementById('vitals');
        if (!wrap || !el) return;
        var h = j.height_cm, w = j.weight_kg;
        if (h != null && h !== '' && w != null && w !== '') {
            el.textContent = Number(h).toFixed(2) + I18N.uCm + ', ' + Number(w).toFixed(2) + I18N.uKg;
            wrap.style.display = '';
        } else {
            el.textContent = I18N.dash;
            wrap.style.display = 'none';
        }
    }
    function formatCurve(t) {
        if (t == null || t === '') return I18N.dash;
        var c = I18N.curve && I18N.curve[t];
        return c != null && c !== '' ? c : String(t);
    }
    async function poll() {
        var errEl = document.getElementById('poll_error');
        errEl.style.display = 'none';
        errEl.textContent = '';
        try {
            var res = await fetch('/inference/reports/' + encodeURIComponent(reportId));
            var j = await res.json();
            lastReport = j;
            setStatusBadge(document.getElementById('status'), j.status);
            document.getElementById('curve').textContent = formatCurve(j.curve_type);
            setVitals(j);
            var editBtn = document.getElementById('open_landmark_editor');
            if (j.status === 'completed') {
                fillCobbSummary(j);
                var o = document.getElementById('img_orig');
                var c = document.getElementById('img_comp');
                var q = 'report_image.php?report_id=' + encodeURIComponent(String(reportId)) + '&kind=';
                if (!o.dataset.loaded) {
                    o.src = q + 'original';
                    o.dataset.loaded = '1';
                }
                if (!c.dataset.loaded || forceImageRefresh) {
                    imageVersion += 1;
                    c.src = q + 'computed&_v=' + imageVersion;
                    c.dataset.loaded = '1';
                    forceImageRefresh = false;
                }
                document.getElementById('img_orig_wrap').style.display = '';
                document.getElementById('img_comp_wrap').style.display = '';
                if (editBtn) editBtn.style.display = '';
                ensureLlmCardLoaded();
            } else if (editBtn) {
                editBtn.style.display = 'none';
            }
            var hint = document.getElementById('img_hint');
            if (j.status === 'pending' || j.status === 'processing') {
                hint.textContent = I18N.js_waiting;
            } else if (j.status === 'failed') {
                hint.textContent = j.error_message || I18N.js_failed;
            } else {
                hint.textContent = '';
            }
        } catch (e) {
            errEl.textContent = I18N.js_poll_err + String(e);
            errEl.style.display = 'block';
        }
    }
    poll();
    setInterval(poll, 4000);

    /* ---------- Manual landmark editor (Konva) ---------- */
    var KONVA_CDN = 'https://cdn.jsdelivr.net/npm/konva@9.3.16/konva.min.js';
    var konvaPromise = null;
    function loadKonva() {
        if (typeof Konva !== 'undefined') return Promise.resolve();
        if (konvaPromise) return konvaPromise;
        konvaPromise = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = KONVA_CDN;
            s.async = true;
            s.onload = function () { resolve(); };
            s.onerror = function () { konvaPromise = null; reject(new Error('Konva load failed')); };
            document.head.appendChild(s);
        });
        return konvaPromise;
    }

    var EDITOR = {
        modalEl: null,
        bsModal: null,
        stage: null,
        bgLayer: null,
        ovLayer: null,
        konvaImage: null,
        imgW: 0,
        imgH: 0,
        scale: 1,
        verts: [],
        nodes: [],
        selectedIdx: -1,
        loaded: false,
        dirty: false,
    };

    function lmkMessage(msg, kind) {
        var el = document.getElementById('lmk_msg');
        if (!el) return;
        if (!msg) { el.style.display = 'none'; el.textContent = ''; return; }
        el.className = 'alert rounded-0 mb-0 py-2 small ' + (kind === 'danger' ? 'alert-danger' : kind === 'success' ? 'alert-success' : 'alert-warning');
        el.textContent = msg;
        el.style.display = '';
    }

    function flatToVerts(flat) {
        var verts = [];
        if (!Array.isArray(flat) || flat.length < 8) return verts;
        var nKp = 4;
        var n = Math.floor(flat.length / (nKp * 2));
        for (var v = 0; v < n; v++) {
            var pts = [];
            for (var k = 0; k < nKp; k++) {
                var i = 2 * (v * nKp + k);
                pts.push({ x: Number(flat[i]) || 0, y: Number(flat[i + 1]) || 0 });
            }
            verts.push(pts);
        }
        return verts;
    }
    function vertsToFlat(verts) {
        var out = [];
        for (var v = 0; v < verts.length; v++) {
            for (var k = 0; k < verts[v].length; k++) {
                out.push(Number(verts[v][k].x) || 0);
                out.push(Number(verts[v][k].y) || 0);
            }
        }
        return out;
    }

    function vertCentroid(p) {
        var cx = 0, cy = 0;
        for (var i = 0; i < p.length; i++) { cx += p[i].x; cy += p[i].y; }
        return { x: cx / p.length, y: cy / p.length };
    }

    function applySelectionStyles() {
        for (var i = 0; i < EDITOR.nodes.length; i++) {
            var n = EDITOR.nodes[i];
            if (!n) continue;
            var isSel = i === EDITOR.selectedIdx;
            n.quad.stroke(isSel ? '#ffeb3b' : '#00e5ff');
            n.quad.strokeWidth(isSel ? 2.5 : 1.6);
            n.quad.fill(isSel ? 'rgba(255,235,59,0.18)' : 'rgba(0,229,255,0.10)');
            for (var k = 0; k < n.circles.length; k++) {
                n.circles[k].fill(isSel ? '#ffeb3b' : '#ff9800');
                n.circles[k].radius(isSel ? 7 : 5.5);
            }
        }
        if (EDITOR.ovLayer) EDITOR.ovLayer.batchDraw();
    }

    function selectVert(idx) {
        if (EDITOR.selectedIdx === idx) return;
        EDITOR.selectedIdx = idx;
        var rb = document.getElementById('lmk_remove');
        if (rb) rb.disabled = !(idx >= 0 && EDITOR.verts.length > 2);
        applySelectionStyles();
    }

    function updateCount() {
        var el = document.getElementById('lmk_count');
        if (!el) return;
        var tmpl = I18N.lmk_count || '{n} vertebrae';
        el.textContent = tmpl.replace('{n}', String(EDITOR.verts.length));
    }

    function rebuildOverlay() {
        if (!EDITOR.ovLayer) return;
        EDITOR.ovLayer.destroyChildren();
        EDITOR.nodes = [];
        var s = EDITOR.scale;
        var pxOrder = [0, 1, 3, 2];
        // Two-pass z-order: first all quads + labels, then ALL handle circles, so a later
        // vertebra’s filled quad never lands on top of an earlier vertebra’s drag handles.
        for (var v = 0; v < EDITOR.verts.length; v++) {
            var pts = EDITOR.verts[v];
            var poly = [];
            for (var i = 0; i < pxOrder.length; i++) {
                poly.push(pts[pxOrder[i]].x * s, pts[pxOrder[i]].y * s);
            }
            var quad = new Konva.Line({
                points: poly,
                stroke: '#00e5ff',
                strokeWidth: 1.6,
                closed: true,
                fill: 'rgba(0,229,255,0.10)',
                perfectDrawEnabled: false,
                hitStrokeWidth: 0,
                shadowForStrokeEnabled: false,
                listening: true,
                transformsEnabled: 'position',
            });
            (function (vIdx, q) {
                q.on('mousedown touchstart', function () { selectVert(vIdx); });
            })(v, quad);
            EDITOR.ovLayer.add(quad);

            var ctr = vertCentroid(pts);
            var label = new Konva.Text({
                x: ctr.x * s - 10,
                y: ctr.y * s - 8,
                text: String(v + 1),
                fontSize: 13,
                fontStyle: 'bold',
                fill: '#fff',
                listening: false,
                perfectDrawEnabled: false,
                transformsEnabled: 'position',
            });
            EDITOR.ovLayer.add(label);

            EDITOR.nodes.push({ quad: quad, label: label, circles: [] });
        }
        for (var v2 = 0; v2 < EDITOR.verts.length; v2++) {
            (function (vIdx) {
                var pts = EDITOR.verts[vIdx];
                var nodeRef = EDITOR.nodes[vIdx];
                for (var k = 0; k < 4; k++) {
                    (function (kpIdx) {
                        var p = pts[kpIdx];
                        var c = new Konva.Circle({
                            x: p.x * s,
                            y: p.y * s,
                            radius: 5.5,
                            fill: '#ff9800',
                            stroke: '#000',
                            strokeWidth: 1,
                            draggable: true,
                            perfectDrawEnabled: false,
                            shadowForStrokeEnabled: false,
                            transformsEnabled: 'position',
                        });
                        c.on('mousedown touchstart', function () { selectVert(vIdx); });
                        c.on('dragstart', function () { c.moveToTop(); });
                        c.on('dragmove', function () {
                            var sNow = EDITOR.scale;
                            EDITOR.verts[vIdx][kpIdx] = {
                                x: c.x() / sNow,
                                y: c.y() / sNow,
                            };
                            EDITOR.dirty = true;
                            var newPoly = [];
                            for (var jj = 0; jj < pxOrder.length; jj++) {
                                newPoly.push(
                                    EDITOR.verts[vIdx][pxOrder[jj]].x * sNow,
                                    EDITOR.verts[vIdx][pxOrder[jj]].y * sNow
                                );
                            }
                            nodeRef.quad.points(newPoly);
                            var nc = vertCentroid(EDITOR.verts[vIdx]);
                            nodeRef.label.position({ x: nc.x * sNow - 10, y: nc.y * sNow - 8 });
                        });
                        c.on('mouseenter', function () { document.body.style.cursor = 'grab'; });
                        c.on('mouseleave', function () { document.body.style.cursor = ''; });
                        EDITOR.ovLayer.add(c);
                        nodeRef.circles.push(c);
                    })(k);
                }
            })(v2);
        }
        applySelectionStyles();
        EDITOR.ovLayer.batchDraw();
        updateCount();
    }

    function rescaleOverlay() {
        if (!EDITOR.ovLayer) return;
        var s = EDITOR.scale;
        var pxOrder = [0, 1, 3, 2];
        for (var v = 0; v < EDITOR.verts.length; v++) {
            var n = EDITOR.nodes[v];
            if (!n) continue;
            var pts = EDITOR.verts[v];
            var poly = [];
            for (var i = 0; i < pxOrder.length; i++) {
                poly.push(pts[pxOrder[i]].x * s, pts[pxOrder[i]].y * s);
            }
            n.quad.points(poly);
            var ctr = vertCentroid(pts);
            n.label.position({ x: ctr.x * s - 10, y: ctr.y * s - 8 });
            for (var k = 0; k < n.circles.length; k++) {
                n.circles[k].position({ x: pts[k].x * s, y: pts[k].y * s });
            }
        }
        EDITOR.ovLayer.batchDraw();
    }

    function fitStageToImage() {
        var wrap = document.getElementById('lmk_stage_wrap');
        if (!wrap || !EDITOR.imgW || !EDITOR.imgH) return;
        var availW = Math.max(320, wrap.clientWidth - 16);
        var availH = Math.max(320, wrap.clientHeight - 16);
        var s = Math.min(availW / EDITOR.imgW, availH / EDITOR.imgH);
        if (!isFinite(s) || s <= 0) s = 1;
        EDITOR.scale = s;
        var sw = Math.round(EDITOR.imgW * s);
        var sh = Math.round(EDITOR.imgH * s);
        if (!EDITOR.stage) {
            // Clamp HiDPI so giant retina canvases don’t balloon during dragmove redraws.
            try { Konva.pixelRatio = Math.min(window.devicePixelRatio || 1, 1.5); } catch (_) {}
            EDITOR.stage = new Konva.Stage({ container: 'lmk_stage', width: sw, height: sh });
            EDITOR.bgLayer = new Konva.Layer({ listening: false });
            EDITOR.ovLayer = new Konva.Layer();
            EDITOR.stage.add(EDITOR.bgLayer);
            EDITOR.stage.add(EDITOR.ovLayer);
            EDITOR.stage.on('mousedown touchstart', function (e) {
                if (e.target === EDITOR.stage) selectVert(-1);
            });
        } else {
            EDITOR.stage.size({ width: sw, height: sh });
        }
        if (EDITOR.konvaImage) {
            EDITOR.konvaImage.size({ width: sw, height: sh });
        }
        EDITOR.bgLayer.batchDraw();
        if (EDITOR.nodes && EDITOR.nodes.length === EDITOR.verts.length) {
            rescaleOverlay();
        } else {
            rebuildOverlay();
        }
    }

    function loadOriginalForEditor() {
        return new Promise(function (resolve, reject) {
            var img = new window.Image();
            img.onload = function () {
                EDITOR.imgW = img.naturalWidth;
                EDITOR.imgH = img.naturalHeight;
                if (EDITOR.bgLayer) {
                    EDITOR.bgLayer.destroyChildren();
                }
                EDITOR.konvaImage = new Konva.Image({
                    image: img,
                    x: 0,
                    y: 0,
                    listening: false,
                    perfectDrawEnabled: false,
                    transformsEnabled: 'position',
                });
                fitStageToImage();
                if (EDITOR.bgLayer) {
                    EDITOR.bgLayer.add(EDITOR.konvaImage);
                    EDITOR.bgLayer.batchDraw();
                }
                resolve();
            };
            img.onerror = function () { reject(new Error('image load failed')); };
            img.src = 'report_image.php?report_id=' + encodeURIComponent(String(reportId)) + '&kind=original';
        });
    }

    async function openEditor() {
        lmkMessage('');
        try {
            await loadKonva();
        } catch (e) {
            lmkMessage(I18N.lmk_load_konva_err, 'danger');
            return;
        }
        if (!lastReport || !Array.isArray(lastReport.landmarks) || lastReport.landmarks.length < 8) {
            lmkMessage(I18N.lmk_no_orig, 'danger');
            return;
        }
        EDITOR.verts = flatToVerts(lastReport.landmarks);
        EDITOR.selectedIdx = -1;
        EDITOR.dirty = false;
        try {
            await loadOriginalForEditor();
        } catch (e) {
            lmkMessage(I18N.lmk_load_err, 'danger');
            return;
        }
        EDITOR.loaded = true;
        document.getElementById('lmk_remove').disabled = true;
        updateCount();
    }

    function vertFromCenter(p, dx, dy) {
        return [
            { x: p.x - dx, y: p.y - dy },
            { x: p.x + dx, y: p.y - dy },
            { x: p.x - dx, y: p.y + dy },
            { x: p.x + dx, y: p.y + dy },
        ];
    }

    function addVertebra() {
        if (EDITOR.verts.length === 0 || !EDITOR.imgW) return;
        var last = EDITOR.verts[EDITOR.verts.length - 1];
        var avg = vertCentroid(last);
        var dx = (Math.abs(last[1].x - last[0].x) || 30) / 2;
        var dy = (Math.abs(last[2].y - last[0].y) || 30) / 2;
        var spacing = dy * 2.4;
        var newCenter = { x: avg.x, y: Math.min(EDITOR.imgH - dy - 2, avg.y + spacing) };
        var pts = vertFromCenter(newCenter, dx, dy);
        var insertAt = EDITOR.selectedIdx >= 0 ? EDITOR.selectedIdx + 1 : EDITOR.verts.length;
        EDITOR.verts.splice(insertAt, 0, pts);
        EDITOR.dirty = true;
        EDITOR.selectedIdx = insertAt;
        rebuildOverlay();
        var rb = document.getElementById('lmk_remove');
        if (rb) rb.disabled = !(EDITOR.selectedIdx >= 0 && EDITOR.verts.length > 2);
    }

    function removeSelected() {
        if (EDITOR.selectedIdx < 0) return;
        if (EDITOR.verts.length <= 2) {
            lmkMessage(I18N.lmk_min, 'warning');
            return;
        }
        EDITOR.verts.splice(EDITOR.selectedIdx, 1);
        EDITOR.selectedIdx = -1;
        EDITOR.dirty = true;
        document.getElementById('lmk_remove').disabled = true;
        rebuildOverlay();
    }

    function resetEditor() {
        if (EDITOR.dirty && !window.confirm(I18N.lmk_confirm_reset)) return;
        if (!lastReport || !Array.isArray(lastReport.landmarks)) return;
        EDITOR.verts = flatToVerts(lastReport.landmarks);
        EDITOR.selectedIdx = -1;
        EDITOR.dirty = false;
        document.getElementById('lmk_remove').disabled = true;
        rebuildOverlay();
        lmkMessage('');
    }

    async function saveEditor() {
        if (EDITOR.verts.length < 2) {
            lmkMessage(I18N.lmk_min, 'warning');
            return;
        }
        var saveBtn = document.getElementById('lmk_save');
        var origLabel = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + I18N.lmk_saving;
        lmkMessage('');
        try {
            var flat = vertsToFlat(EDITOR.verts);
            var res = await fetch('recompute_landmarks.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    csrf_token: CSRF_TOKEN,
                    report_id: reportId,
                    landmarks: flat,
                }),
            });
            var j = null;
            try { j = await res.json(); } catch (_) {}
            if (!res.ok || !j || !j.ok) {
                var err = (j && j.error) ? j.error : ('HTTP ' + res.status);
                lmkMessage(err, 'danger');
                return;
            }
            EDITOR.dirty = false;
            lmkMessage(I18N.lmk_save_ok, 'success');
            forceImageRefresh = true;
            if (EDITOR.bsModal) EDITOR.bsModal.hide();
            poll();
        } catch (e) {
            lmkMessage(String(e && e.message ? e.message : e), 'danger');
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = origLabel;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        EDITOR.modalEl = document.getElementById('svLandmarkEditor');
        if (!EDITOR.modalEl || typeof bootstrap === 'undefined') return;
        EDITOR.bsModal = new bootstrap.Modal(EDITOR.modalEl);
        EDITOR.modalEl.addEventListener('shown.bs.modal', function () {
            openEditor();
        });
        EDITOR.modalEl.addEventListener('hide.bs.modal', function (ev) {
            if (EDITOR.dirty && !window.confirm(I18N.lmk_confirm_close)) {
                ev.preventDefault();
                return;
            }
        });
        EDITOR.modalEl.addEventListener('hidden.bs.modal', function () {
            if (EDITOR.stage) {
                try { EDITOR.stage.destroy(); } catch (_) {}
            }
            EDITOR.stage = null;
            EDITOR.bgLayer = null;
            EDITOR.ovLayer = null;
            EDITOR.konvaImage = null;
            EDITOR.nodes = [];
            EDITOR.loaded = false;
            EDITOR.dirty = false;
            EDITOR.selectedIdx = -1;
            EDITOR.verts = [];
            lmkMessage('');
            var rb = document.getElementById('lmk_remove');
            if (rb) rb.disabled = true;
        });
        document.getElementById('lmk_add').addEventListener('click', addVertebra);
        document.getElementById('lmk_remove').addEventListener('click', removeSelected);
        document.getElementById('lmk_reset').addEventListener('click', resetEditor);
        document.getElementById('lmk_save').addEventListener('click', saveEditor);
        window.addEventListener('resize', function () {
            if (EDITOR.loaded) fitStageToImage();
        });
    });

    /* ---------- AI preliminary description ---------- */
    // Generation is now async on the inference-worker-llm side. POST enqueues
    // and returns 202 with { job:{id, status} }; we then poll GET every
    // LLM_POLL_MS until either a newer draft.id appears or job.status === 'failed'.
    var LLM = {
        loaded: false,
        busy: false,
        hasDraft: false,
        lastDraftId: 0,
        pollTimer: null,
    };
    var LLM_POLL_MS = 2000;
    // 30 minutes hard cap (matches the active-job key TTL on the inference side).
    var LLM_POLL_MAX = (30 * 60 * 1000) / LLM_POLL_MS;

    function llmStopPolling() {
        if (LLM.pollTimer) {
            clearTimeout(LLM.pollTimer);
            LLM.pollTimer = null;
        }
    }

    function llmStatusForJob(job) {
        if (!job || !job.status) return I18N.llm_generating || '';
        if (job.status === 'queued' || job.status === 'deferred' || job.status === 'scheduled') {
            return I18N.llm_queued || I18N.llm_generating || '';
        }
        if (job.status === 'started') {
            return I18N.llm_running || I18N.llm_generating || '';
        }
        return I18N.llm_generating || '';
    }

    function llmFmtDate(s) {
        if (!s) return '';
        try {
            var d = new Date(s);
            if (isNaN(d.getTime())) return String(s);
            return d.toLocaleString();
        } catch (_) { return String(s); }
    }

    function llmFormatMeta(d) {
        if (!d || !I18N.llm_meta) return '';
        return I18N.llm_meta
            .replace('{model}', String(d.model_tag || ''))
            .replace('{when}', llmFmtDate(d.created_at))
            .replace('{secs}', ((Number(d.latency_ms || 0) / 1000)).toFixed(1));
    }

    function llmRender(draft) {
        var card = document.getElementById('llm_card');
        var empty = document.getElementById('llm_empty');
        var body = document.getElementById('llm_draft');
        var meta = document.getElementById('llm_meta');
        var btnLabel = document.getElementById('llm_btn_label');
        if (!card) return;
        card.style.display = '';
        if (draft && draft.response_text) {
            empty.style.display = 'none';
            body.style.display = '';
            body.textContent = String(draft.response_text);
            meta.textContent = llmFormatMeta(draft);
            btnLabel.textContent = I18N.llm_regenerate || I18N.llm_generate || 'Regenerate';
            LLM.hasDraft = true;
            LLM.lastDraftId = Number(draft.id || 0) || LLM.lastDraftId;
        } else {
            empty.style.display = '';
            body.style.display = 'none';
            body.textContent = '';
            meta.textContent = '';
            btnLabel.textContent = I18N.llm_generate || 'Generate';
            LLM.hasDraft = false;
        }
    }

    function llmShowError(msg) {
        var el = document.getElementById('llm_error');
        if (!el) return;
        if (msg) {
            el.textContent = String(msg);
            el.style.display = '';
        } else {
            el.textContent = '';
            el.style.display = 'none';
        }
    }

    function llmShowStatus(msg) {
        var el = document.getElementById('llm_status');
        if (!el) return;
        if (msg) { el.textContent = String(msg); el.style.display = ''; }
        else { el.textContent = ''; el.style.display = 'none'; }
    }

    async function llmFetchState() {
        var res = await fetch('generate_draft_impression.php?report_id=' + encodeURIComponent(String(reportId)), {
            headers: { 'Accept': 'application/json' },
        });
        var j = await res.json();
        if (!res.ok) throw new Error((j && j.error) || ('HTTP ' + res.status));
        return j || {};
    }

    async function ensureLlmCardLoaded() {
        var card = document.getElementById('llm_card');
        if (card) card.style.display = '';
        // While the client is polling for draft completion, the 2s LLM loop owns refreshes.
        if (LLM.busy || LLM.pollTimer) return;
        // Stable draft and idle: skip redundant GETs on every 4s report poll.
        if (LLM.loaded && LLM.hasDraft) return;
        try {
            var j = await llmFetchState();
            if (!LLM.loaded) LLM.loaded = true;
            var draft = j && j.draft ? j.draft : null;
            if (draft && draft.response_text) {
                var newId = Number(draft.id || 0) || 0;
                if (newId > LLM.lastDraftId || !LLM.hasDraft) {
                    llmRender(draft);
                }
            } else if (!LLM.hasDraft) {
                llmRender(null);
            }
            // Auto-enqueued draft (or tab resume): job may appear after the first fetch.
            var job = j && j.job ? j.job : null;
            if (job && (job.status === 'queued' || job.status === 'started' || job.status === 'deferred' || job.status === 'scheduled')) {
                llmBeginPolling();
            }
        } catch (e) {
            LLM.loaded = false;
            llmShowError(I18N.llm_load_err + ' ' + String(e.message || e));
        }
    }

    function llmBeginPolling() {
        llmStopPolling();
        LLM.busy = true;
        var btn = document.getElementById('llm_generate');
        if (btn) btn.disabled = true;
        llmShowStatus(I18N.llm_generating || '');

        var ticks = 0;
        var prevDraftId = LLM.lastDraftId;
        async function tick() {
            LLM.pollTimer = null;
            ticks++;
            try {
                var j = await llmFetchState();
                var draft = j && j.draft ? j.draft : null;
                var job = j && j.job ? j.job : null;
                var newDraftId = draft ? (Number(draft.id || 0) || 0) : 0;

                // Done: a fresh draft (newer than the one we had before POST) was persisted.
                if (newDraftId > prevDraftId) {
                    llmRender(draft);
                    llmStopPolling();
                    llmShowStatus('');
                    LLM.busy = false;
                    if (btn) btn.disabled = false;
                    return;
                }

                if (job) {
                    if (job.status === 'failed') {
                        llmStopPolling();
                        llmShowStatus('');
                        LLM.busy = false;
                        if (btn) btn.disabled = false;
                        llmShowError((I18N.llm_gen_err || 'Error: ') + String(job.error || 'failed'));
                        return;
                    }
                    if (job.status === 'finished' && newDraftId <= prevDraftId) {
                        // Worker reports done but no fresh draft row yet — give the DB write a tick.
                        // Fall through to scheduling another poll.
                    }
                    llmShowStatus(llmStatusForJob(job));
                }

                if (ticks >= LLM_POLL_MAX) {
                    llmStopPolling();
                    llmShowStatus('');
                    LLM.busy = false;
                    if (btn) btn.disabled = false;
                    llmShowError((I18N.llm_gen_err || 'Error: ') + 'timed out waiting for draft.');
                    return;
                }
            } catch (e) {
                // Transient errors don't abort the loop; we just surface them and try again.
                llmShowError(I18N.llm_load_err + ' ' + String(e.message || e));
            }
            LLM.pollTimer = setTimeout(tick, LLM_POLL_MS);
        }
        LLM.pollTimer = setTimeout(tick, LLM_POLL_MS);
    }

    async function llmGenerate() {
        if (LLM.busy) return;
        if (LLM.hasDraft && I18N.llm_confirm_regen && !window.confirm(I18N.llm_confirm_regen)) return;
        LLM.busy = true;
        var btn = document.getElementById('llm_generate');
        if (btn) btn.disabled = true;
        llmShowError('');
        llmShowStatus(I18N.llm_queued || I18N.llm_generating || '');
        try {
            var res = await fetch('generate_draft_impression.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ csrf_token: CSRF_TOKEN, report_id: reportId }),
            });
            var j = await res.json();
            // 202 Accepted is the normal happy path; any non-2xx is an error.
            if (!res.ok || !j || j.ok !== true) {
                throw new Error((j && j.error) || ('HTTP ' + res.status));
            }
            llmBeginPolling();
        } catch (e) {
            llmShowStatus('');
            llmShowError((I18N.llm_gen_err || 'Error: ') + String(e.message || e));
            if (btn) btn.disabled = false;
            LLM.busy = false;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('llm_generate');
        if (btn) btn.addEventListener('click', llmGenerate);
    });
</script>
<?php sv_layout_end(); ?>

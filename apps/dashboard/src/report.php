<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/back.php';
require __DIR__ . '/inc/pagination.php';

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
    echo 'Missing report id';
    exit;
}

$vitalsErrors = [];
$postedBack = isset($_POST['back']) ? (string) $_POST['back'] : null;
$safeBack = sv_back_validate($postedBack) ?? sv_back_validate(sv_back_get_raw());

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report_vitals'])) {
    sv_csrf_check();
    if ((int) ($_POST['report_id'] ?? 0) !== $id) {
        http_response_code(400);
        echo 'Invalid report id';
        exit;
    }

    $hRaw = trim((string) ($_POST['height_cm'] ?? ''));
    $wRaw = trim((string) ($_POST['weight_kg'] ?? ''));
    $hDb = null;
    $wDb = null;

    if ($hRaw !== '') {
        $hs = str_replace(',', '.', $hRaw);
        if (!preg_match('/^\d+(\.\d+)?$/', $hs)) {
            $vitalsErrors[] = 'Height (cm) must be a number.';
        } else {
            $hv = (float) $hs;
            if ($hv < 50.0 || $hv > 250.0) {
                $vitalsErrors[] = 'Height (cm) must be between 50 and 250.';
            } else {
                $hDb = round($hv, 2);
            }
        }
    }
    if ($wRaw !== '') {
        $ws = str_replace(',', '.', $wRaw);
        if (!preg_match('/^\d+(\.\d+)?$/', $ws)) {
            $vitalsErrors[] = 'Weight (kg) must be a number.';
        } else {
            $wv = (float) $ws;
            if ($wv < 10.0 || $wv > 350.0) {
                $vitalsErrors[] = 'Weight (kg) must be between 10 and 350.';
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
            echo 'Report not found';
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
    echo 'Report not found';
    exit;
}

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
$vitalsText = $showVitals
    ? number_format((float) $rvH, 2) . ' cm, ' . number_format((float) $rvW, 2) . ' kg'
    : '—';

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

sv_layout_start('Report #' . (int) $row['id']);
$patientBreadcrumbLabel = $row['last_name'] . ', ' . $row['first_name'];
ob_start();
if ($hasBack): ?>
            <a href="<?= htmlspecialchars(sv_back_or('queue.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left fa-fw"></i> Back
            </a>
        <?php else: ?>
            <a href="queue.php" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left fa-fw"></i> Queue
            </a>
        <?php endif;
$topActions = ob_get_clean();
sv_topbar(
    [
        sv_crumb('Dashboard', 'index.php'),
        sv_crumb('Patients', 'patients.php'),
        sv_crumb($patientBreadcrumbLabel, 'patient.php?id=' . (int) $row['patient_id']),
        sv_crumb('Report #' . (int) $row['id'], null),
    ],
    '<i class="fa-solid fa-file-medical"></i> Report #' . (int) $row['id'],
    $topActions
);
?>

<?php if ($vitalsErrors !== []): ?>
    <div class="alert alert-danger mb-3" role="alert">
        <strong>Could not save height / weight.</strong>
        <ul class="mb-0 mt-2"><?php foreach ($vitalsErrors as $msg): ?>
            <li><?= htmlspecialchars((string) $msg, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?></ul>
    </div>
<?php endif; ?>
<?php if ($vitalsSavedOk): ?>
    <div class="alert alert-success mb-3" role="alert">
        <strong>Saved.</strong> Height and weight for this report were updated.
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
                <i class="fa-solid fa-user-injured fa-fw"></i> Patient
            </a>
        </p>
        <p class="mb-0">
            Status: <span id="status" class="<?= sv_status_class($row['status']) ?>"><?= sv_status_icon_markup($row['status']) ?><span class="sv-status-text"><?= htmlspecialchars($row['status']) ?></span></span>
            &nbsp; Curve: <span id="curve" class="fw-semibold"><?= htmlspecialchars($row['curve_type'] ?? '—') ?></span>
            <span class="text-muted" id="vitals_wrap"<?= $showVitals ? '' : ' style="display:none;"' ?>> &middot; At exam: <strong id="vitals"><?= htmlspecialchars($vitalsText) ?></strong></span>
        </p>
    </div>
</div>

<div class="sv-report-main-grid">
    <div class="sv-report-main-left d-flex flex-column min-h-0 gap-3">
        <div class="sv-card flex-shrink-0">
            <div class="sv-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span><i class="fa-solid fa-ruler-combined"></i> Scoliosis Measurements</span>
                <?php if ($canReviseMetrics): ?>
                    <a href="<?= htmlspecialchars(sv_append_back('report_edit_metrics.php?id=' . (int) $row['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary">
                        <i class="fa-solid fa-pen fa-fw"></i> Edit
                    </a>
                <?php endif; ?>
            </div>
            <div class="sv-card-body p-0">
                <table class="table mb-0" id="cobb_summary">
                    <tbody>
                        <tr><th>PT (°)</th><td id="cobb_pt">—</td></tr>
                        <tr><th>MT (°)</th><td id="cobb_mt">—</td></tr>
                        <tr><th>TL (°)</th><td id="cobb_tl">—</td></tr>
                        <tr><th>Thoracic (max PT/MT) (°)</th><td id="cobb_thoracic">—</td></tr>
                        <tr><th>Lumbar (TL) (°)</th><td id="cobb_lumbar">—</td></tr>
                        <tr><th>Max Cobb</th><td id="cobb_max">—</td></tr>
                        <tr><th>Max vertebrae (1-based)</th><td id="cobb_verts">—</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-stretch flex-grow-1 min-h-0 sv-report-vitals-pdf-row">
            <div class="d-flex align-items-stretch flex-shrink-0 sv-report-vitals-pdf-col">
                <div class="sv-card sv-report-vitals-card w-100 d-flex flex-column">
                    <div class="sv-card-header">
                        <i class="fa-solid fa-ruler-vertical"></i> Height &amp; weight
                    </div>
                    <div class="sv-card-body d-flex flex-column flex-grow-1">
                        <form class="d-flex flex-column flex-grow-1" method="post" action="<?= htmlspecialchars(sv_back_preserve_on('report.php?id=' . (int) $id), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(sv_csrf_token()) ?>">
                            <input type="hidden" name="report_id" value="<?= (int) $id ?>">
                            <input type="hidden" name="back" value="<?= htmlspecialchars((string) (sv_back_get_raw() ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="mb-3">
                                <label class="form-label" for="height_cm">Height (cm)</label>
                                <input class="form-control" id="height_cm" name="height_cm" inputmode="decimal" autocomplete="off"
                                       placeholder="e.g. 165" value="<?= htmlspecialchars($vitalsFormH, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="weight_kg">Weight (kg)</label>
                                <input class="form-control" id="weight_kg" name="weight_kg" inputmode="decimal" autocomplete="off"
                                       placeholder="e.g. 58.5" value="<?= htmlspecialchars($vitalsFormW, ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="mt-auto pt-1">
                                <button type="submit" name="save_report_vitals" value="1" class="btn btn-primary">
                                    <i class="fa-solid fa-floppy-disk fa-fw"></i> Save
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
                            <i class="fa-solid fa-file-pdf"></i> PDF reports for this scan
                            <span class="text-muted fw-normal">(<?= (int) $pdfTotalCount ?> · newest first<?= $pdfTotalPages > 1 ? ' · page ' . (int) $pdfPage . ' / ' . (int) $pdfTotalPages : '' ?>)</span>
                        </span>
                        <a href="<?= htmlspecialchars(sv_append_back('new_pdf.php?report_id=' . (int) $row['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-primary">
                            <i class="fa-solid fa-file-pdf fa-fw"></i> Create PDF
                        </a>
                    </div>
                    <div class="sv-card-body p-0 d-flex flex-column flex-grow-1 min-h-0">
                        <div class="table-responsive sv-report-pdf-table-wrap flex-grow-1 min-h-0">
                            <table class="table table-striped mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>PDF</th>
                                        <th>Title</th>
                                        <th>Physician</th>
                                        <th>Created</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($pdfReports as $pr): ?>
                                    <tr id="pdf-<?= (int) $pr['id'] ?>">
                                        <td><strong>#<?= (int) $pr['id'] ?></strong></td>
                                        <td><?= htmlspecialchars((string) ($pr['title'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
                                        <td><?php
                                            $dn = trim((string) ($pr['physician_display_name'] ?? ''));
                                            $un = (string) ($pr['physician_username'] ?? '');
                                            if ($dn !== '') {
                                                echo htmlspecialchars($dn);
                                            } elseif ($un !== '') {
                                                echo '<code>' . htmlspecialchars($un) . '</code>';
                                            } else {
                                                echo '<span class="text-muted">—</span>';
                                            }
                                        ?></td>
                                        <td><span class="text-muted small"><?= htmlspecialchars((string) $pr['created_at']) ?></span></td>
                                        <td class="text-end">
                                            <a href="pdf.php?id=<?= (int) $pr['id'] ?>" target="_blank" rel="noopener"
                                               class="btn btn-primary btn-sm"
                                               aria-label="Open PDF"><i class="fa-solid fa-download fa-fw"></i></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$pdfReports): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">
                                        No PDFs yet. Use <strong>Create PDF</strong> above to generate one from this report.
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
            <div class="sv-card-header flex-shrink-0">
                <i class="fa-solid fa-images"></i> Images
                <span class="text-muted fw-normal small">Click an image for full size</span>
            </div>
            <div class="sv-card-body flex-grow-1 d-flex flex-column min-h-0">
                <div class="row g-2 sv-report-image-row flex-grow-1 align-items-stretch min-h-0">
                    <div class="col-6 d-flex min-h-0">
                        <div class="sv-report-thumb-wrap flex-grow-1 w-100" id="img_orig_wrap" style="display:none;" role="button" tabindex="0" aria-label="View original full size">
                            <img id="img_orig" class="sv-report-thumb" alt="Original X-ray">
                            <span class="sv-report-thumb-label">Original</span>
                        </div>
                    </div>
                    <div class="col-6 d-flex min-h-0">
                        <div class="sv-report-thumb-wrap flex-grow-1 w-100" id="img_comp_wrap" style="display:none;" role="button" tabindex="0" aria-label="View overlay full size">
                            <img id="img_comp" class="sv-report-thumb" alt="Computed overlay">
                            <span class="sv-report-thumb-label">Overlay</span>
                        </div>
                    </div>
                </div>
                <p id="img_hint" class="text-muted mb-0 mt-2 small"></p>
                <p id="poll_error" class="text-danger mb-0 mt-2 small" style="display:none;"></p>
            </div>
        </div>
    </div>
</div><!-- /.sv-report-main-grid -->

<div class="modal fade" id="svImgLightbox" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-fullscreen-lg-down">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary py-2">
                <h5 class="modal-title" id="svImgLightboxTitle">Image</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-2 text-center d-flex align-items-center justify-content-center" style="min-height: 50vh;">
                <img id="svImgLightboxImg" class="img-fluid rounded" alt="" style="max-height: 85vh; width: auto;">
            </div>
        </div>
    </div>
</div>

<script>
    var reportId = <?= json_encode($id) ?>;
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
    wireThumb('img_orig_wrap', 'img_orig', 'Original');
    wireThumb('img_comp_wrap', 'img_comp', 'Computed overlay');

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
        text.textContent = s;
        el.appendChild(icon);
        el.appendChild(text);
    }
    function fmtDeg(x) {
        if (x === null || x === undefined || x === '') return '—';
        var n = Number(x);
        return isNaN(n) ? '—' : n.toFixed(2);
    }
    function fillCobbSummary(j) {
        document.getElementById('cobb_pt').textContent = fmtDeg(j.cobb_pt_deg);
        document.getElementById('cobb_mt').textContent = fmtDeg(j.cobb_mt_deg);
        document.getElementById('cobb_tl').textContent = fmtDeg(j.cobb_tl_deg);
        document.getElementById('cobb_thoracic').textContent = fmtDeg(j.cobb_thoracic_deg);
        document.getElementById('cobb_lumbar').textContent = fmtDeg(j.cobb_lumbar_deg);
        var maxLine = '—';
        if (j.cobb_max_deg != null && j.cobb_max_deg !== '') {
            var reg = (j.cobb_max_region || '').toString().toUpperCase();
            maxLine = reg + ' ' + fmtDeg(j.cobb_max_deg) + '°';
        }
        document.getElementById('cobb_max').textContent = maxLine;
        var vs = j.cobb_max_vert_superior, vi = j.cobb_max_vert_inferior;
        document.getElementById('cobb_verts').textContent =
            (vs != null && vi != null) ? (String(vs) + ' – ' + String(vi)) : '—';
    }
    function setVitals(j) {
        var wrap = document.getElementById('vitals_wrap');
        var el = document.getElementById('vitals');
        if (!wrap || !el) return;
        var h = j.height_cm, w = j.weight_kg;
        if (h != null && h !== '' && w != null && w !== '') {
            el.textContent = Number(h).toFixed(2) + ' cm, ' + Number(w).toFixed(2) + ' kg';
            wrap.style.display = '';
        } else {
            el.textContent = '—';
            wrap.style.display = 'none';
        }
    }
    async function poll() {
        var errEl = document.getElementById('poll_error');
        errEl.style.display = 'none';
        errEl.textContent = '';
        try {
            var res = await fetch('/inference/reports/' + encodeURIComponent(reportId));
            var j = await res.json();
            setStatusBadge(document.getElementById('status'), j.status);
            document.getElementById('curve').textContent = j.curve_type || '—';
            setVitals(j);
            if (j.status === 'completed') {
                fillCobbSummary(j);
            }
            var o = document.getElementById('img_orig');
            var c = document.getElementById('img_comp');
            if (j.presigned_original) {
                o.src = j.presigned_original;
                document.getElementById('img_orig_wrap').style.display = '';
            }
            if (j.presigned_computed) {
                c.src = j.presigned_computed;
                document.getElementById('img_comp_wrap').style.display = '';
            }
            var hint = document.getElementById('img_hint');
            if (j.status === 'pending' || j.status === 'processing') {
                hint.textContent = 'Waiting for automatic measurements…';
            } else if (j.status === 'failed') {
                hint.textContent = j.error_message || 'Failed';
            } else {
                hint.textContent = '';
            }
        } catch (e) {
            errEl.textContent = 'Could not refresh report: ' + String(e);
            errEl.style.display = 'block';
        }
    }
    poll();
    setInterval(poll, 4000);
</script>
<?php sv_layout_end(); ?>

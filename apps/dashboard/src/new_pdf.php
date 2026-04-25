<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/back.php';
require __DIR__ . '/inc/pdf.php';

$user = sv_require_auth();

$reportId = isset($_GET['report_id']) ? (int) $_GET['report_id'] : 0;
if ($reportId <= 0 && isset($_POST['report_id'])) {
    $reportId = (int) $_POST['report_id'];
}
if ($reportId <= 0) {
    http_response_code(400);
    echo 'Missing report_id';
    exit;
}

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT r.*, p.first_name, p.last_name, p.tax_code
     FROM reports r JOIN patients p ON p.id = r.patient_id WHERE r.id = ?'
);
$stmt->execute([$reportId]);
$report = $stmt->fetch();
if (!$report) {
    http_response_code(404);
    echo 'Report not found';
    exit;
}

$errors = [];
$createdPdfId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sv_csrf_check();

    $title = trim((string) ($_POST['title'] ?? ''));
    $notes = (string) ($_POST['notes'] ?? '');
    $includeImage = isset($_POST['include_image']) && $_POST['include_image'] === '1';

    if ($title === '') {
        $title = 'Report #' . (int) $report['id']
            . ' — ' . (string) $report['last_name'] . ', ' . (string) $report['first_name'];
    }

    if (mb_strlen($title) > 200) {
        $errors[] = 'Title is too long (max 200 chars).';
    }
    if (mb_strlen($notes) > 50000) {
        $errors[] = 'Notes are too long (max 50000 chars).';
    }

    if (!$errors) {
        $imageBytes = null;
        if ($includeImage && (string) ($report['computed_object_key'] ?? '') !== '') {
            $infUrl = sv_inference_base_url() . '/reports/' . (int) $report['id'];
            $jsonBytes = sv_fetch_url_bytes($infUrl, 10);
            if ($jsonBytes !== null) {
                $j = json_decode($jsonBytes, true);
                $presigned = is_array($j) ? ($j['presigned_computed'] ?? null) : null;
                if (is_string($presigned) && $presigned !== '') {
                    $imageBytes = sv_fetch_url_bytes($presigned, 20);
                }
            }
        }

        try {
            $pdfBytes = sv_render_report_pdf(
                $report,
                $user,
                $title,
                $notes,
                $imageBytes
            );
        } catch (Throwable $e) {
            $errors[] = 'PDF rendering failed: ' . $e->getMessage();
            $pdfBytes = '';
        }

        if (!$errors && $pdfBytes !== '') {
            $uploadErr = '';
            $resp = sv_upload_pdf_to_inference(
                (int) $report['id'],
                (int) $user['id'],
                (string) $user['username'],
                $title,
                $notes,
                $pdfBytes,
                $uploadErr
            );
            if ($resp === null) {
                $errors[] = $uploadErr ?: 'Could not upload the PDF.';
            } else {
                $createdPdfId = (int) $resp['id'];
                $rid = (int) $report['id'];
                $loc = 'report.php?id=' . $rid;
                $br = sv_back_get_raw();
                if ($br !== null && sv_back_validate($br) !== null) {
                    $loc .= '&back=' . rawurlencode($br);
                }
                $loc .= '#pdf-' . (int) $resp['id'];
                header('Location: ' . $loc);
                exit;
            }
        }
    }
}

sv_layout_start('Create PDF · Report #' . (int) $report['id']);
$pdfPatientCrumb = $report['last_name'] . ', ' . $report['first_name'];
ob_start(); ?>
        <a href="<?= htmlspecialchars(sv_back_or('report.php?id=' . (int) $report['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left fa-fw"></i> Back
        </a>
        <a href="<?= htmlspecialchars(sv_append_back('report.php?id=' . (int) $report['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
            <i class="fa-solid fa-file-medical fa-fw"></i> Report
        </a>
        <a href="<?= htmlspecialchars(sv_append_back('patient.php?id=' . (int) $report['patient_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
            <i class="fa-solid fa-user-injured fa-fw"></i> Patient
        </a>
<?php
$topActions = ob_get_clean();
sv_topbar(
    [
        sv_crumb('Dashboard', 'index.php'),
        sv_crumb('Patients', 'patients.php'),
        sv_crumb($pdfPatientCrumb, 'patient.php?id=' . (int) $report['patient_id']),
        sv_crumb('Report #' . (int) $report['id'], 'report.php?id=' . (int) $report['id']),
        sv_crumb('Create PDF', null),
    ],
    '<i class="fa-solid fa-file-pdf"></i> Create PDF report',
    $topActions
);
?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Could not create the PDF.</strong>
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="sv-card mb-3">
    <div class="sv-card-header">
        <i class="fa-solid fa-id-card"></i> Source report
    </div>
    <div class="sv-card-body small">
        <div class="row g-2">
            <div class="col-md-3"><span class="text-muted">Report</span><br><strong>#<?= (int) $report['id'] ?></strong></div>
            <div class="col-md-3"><span class="text-muted">Patient</span><br><?= htmlspecialchars($report['first_name'] . ' ' . $report['last_name']) ?></div>
            <div class="col-md-3"><span class="text-muted">Tax code</span><br><code><?= htmlspecialchars($report['tax_code']) ?></code></div>
            <div class="col-md-3"><span class="text-muted">Status</span><br>
                <span class="<?= sv_status_class($report['status']) ?>">
                    <?= sv_status_icon_markup($report['status']) ?>
                    <span class="sv-status-text"><?= htmlspecialchars($report['status']) ?></span>
                </span>
            </div>
            <div class="col-md-3"><span class="text-muted">Curve</span><br><?= htmlspecialchars($report['curve_type'] ?? '—') ?></div>
            <div class="col-md-3"><span class="text-muted">Max Cobb</span><br>
                <?php
                $reg = (string) ($report['cobb_max_region'] ?? '');
                $mx = $report['cobb_max_deg'] ?? null;
                echo ($mx !== null && $mx !== '')
                    ? htmlspecialchars(strtoupper($reg) . ' ' . number_format((float) $mx, 2) . '°')
                    : '—';
                ?>
            </div>
            <div class="col-md-3"><span class="text-muted">Thoracic</span><br><?= sv_fmt_deg($report['cobb_thoracic_deg'] ?? null) ?> °</div>
            <div class="col-md-3"><span class="text-muted">Lumbar</span><br><?= sv_fmt_deg($report['cobb_lumbar_deg'] ?? null) ?> °</div>
            <div class="col-md-3"><span class="text-muted">Height (exam)</span><br><?php
                $hc = $report['height_cm'] ?? null;
                echo ($hc !== null && $hc !== '' && is_numeric($hc)) ? htmlspecialchars(number_format((float) $hc, 2)) . ' cm' : '—';
            ?></div>
            <div class="col-md-3"><span class="text-muted">Weight (exam)</span><br><?php
                $wk = $report['weight_kg'] ?? null;
                echo ($wk !== null && $wk !== '' && is_numeric($wk)) ? htmlspecialchars(number_format((float) $wk, 2)) . ' kg' : '—';
            ?></div>
        </div>
    </div>
</div>

<div class="sv-card">
    <div class="sv-card-header">
        <i class="fa-solid fa-pen-to-square"></i> PDF content
    </div>
    <div class="sv-card-body">
        <?php if ($report['status'] !== 'completed'): ?>
            <div class="alert alert-warning small">
                Note: this report is <strong><?= htmlspecialchars($report['status']) ?></strong>.
                The Cobb summary may be incomplete and the overlay image may be missing.
            </div>
        <?php endif; ?>
        <form method="post" action="new_pdf.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(sv_csrf_token()) ?>">
            <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">

            <div class="mb-3">
                <label class="form-label">Title</label>
                <input class="form-control" name="title" maxlength="200"
                       placeholder="Report #<?= (int) $report['id'] ?> — <?= htmlspecialchars($report['last_name']) ?>, <?= htmlspecialchars($report['first_name']) ?>"
                       value="<?= htmlspecialchars((string) ($_POST['title'] ?? '')) ?>">
                <div class="form-text">Defaults to a sensible patient + report header if left blank.</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Physician notes</label>
                <textarea class="form-control" name="notes" rows="10"
                          placeholder="Clinical impression, recommended follow-up, treatment plan…"><?= htmlspecialchars((string) ($_POST['notes'] ?? '')) ?></textarea>
                <div class="form-text">Plain text. Line breaks are preserved.</div>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="include_image" name="include_image" value="1"
                    <?php
                    $checked = isset($_POST['include_image'])
                        ? ($_POST['include_image'] === '1')
                        : ($report['status'] === 'completed' && (string) ($report['computed_object_key'] ?? '') !== '');
                    echo $checked ? ' checked' : '';
                    ?>>
                <label class="form-check-label" for="include_image">
                    Embed the computed overlay image (X-ray + landmarks)
                </label>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-file-pdf fa-fw"></i> Generate &amp; store PDF
            </button>
        </form>
    </div>
</div>
<?php sv_layout_end(); ?>

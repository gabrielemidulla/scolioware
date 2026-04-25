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
    echo htmlspecialchars((string) __('error.missing_report_id_param'), ENT_QUOTES, 'UTF-8');
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
    echo htmlspecialchars((string) __('error.report_not_found'), ENT_QUOTES, 'UTF-8');
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
        $title = (string) __('new_pdf.default_title', [
            'rid' => (string) (int) $report['id'],
            'surname' => (string) $report['last_name'],
            'name' => (string) $report['first_name'],
        ]);
    }

    if (mb_strlen($title) > 200) {
        $errors[] = (string) __('new_pdf.err_title_len');
    }
    if (mb_strlen($notes) > 50000) {
        $errors[] = (string) __('new_pdf.err_notes_len');
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
            $errors[] = (string) __('new_pdf.err_render') . $e->getMessage();
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
                $errors[] = $uploadErr !== '' ? $uploadErr : (string) __('new_pdf.err_upload');
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

$npfTitle = (string) __('new_pdf.title', ['id' => (string) (int) $report['id']]);
sv_layout_start($npfTitle);
$pdfPatientCrumb = $report['last_name'] . ', ' . $report['first_name'];
$defaultPh = (string) __('new_pdf.default_title', [
    'rid' => (string) (int) $report['id'],
    'surname' => (string) $report['last_name'],
    'name' => (string) $report['first_name'],
]);
ob_start(); ?>
        <a href="<?= htmlspecialchars(sv_back_or('report.php?id=' . (int) $report['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
            <i class="fa-solid fa-arrow-left fa-fw"></i> <?= htmlspecialchars((string) __('common.back'), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <a href="<?= htmlspecialchars(sv_append_back('report.php?id=' . (int) $report['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
            <i class="fa-solid fa-file-medical fa-fw"></i> <?= htmlspecialchars(
                (string) __('common.report'),
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </a>
        <a href="<?= htmlspecialchars(sv_append_back('patient.php?id=' . (int) $report['patient_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
            <i class="fa-solid fa-user-injured fa-fw"></i> <?= htmlspecialchars(
                (string) __('common.patient'),
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </a>
<?php
$topActions = ob_get_clean();
$repCrum = (string) __('report.title', ['id' => (string) (int) $report['id']]);
$lastCr = (string) __('new_pdf.heading');
sv_topbar(
    [
        sv_crumb((string) __('nav.dashboard'), 'index.php'),
        sv_crumb((string) __('nav.patients'), 'patients.php'),
        sv_crumb($pdfPatientCrumb, 'patient.php?id=' . (int) $report['patient_id']),
        sv_crumb($repCrum, 'report.php?id=' . (int) $report['id']),
        sv_crumb($lastCr, null),
    ],
    '<i class="fa-solid fa-file-pdf"></i> ' . htmlspecialchars(
        (string) __('new_pdf.heading'),
        ENT_QUOTES,
        'UTF-8'
    ),
    $topActions
);
?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong><?= htmlspecialchars((string) __('new_pdf.err_title'), ENT_QUOTES, 'UTF-8') ?></strong>
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="sv-card mb-3">
    <div class="sv-card-header">
        <i class="fa-solid fa-id-card"></i> <?= htmlspecialchars((string) __('new_pdf.source'), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div class="sv-card-body small">
        <div class="row g-2">
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars(
                (string) __('new_pdf.sub_report'),
                ENT_QUOTES,
                'UTF-8'
            ) ?></span><br><strong>#<?= (int) $report['id'] ?></strong></div>
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars(
                (string) __('new_pdf.sub_patient'),
                ENT_QUOTES,
                'UTF-8'
            ) ?></span><br><?= htmlspecialchars($report['first_name'] . ' ' . $report['last_name']) ?></div>
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars(
                (string) __('patient.tax'),
                ENT_QUOTES,
                'UTF-8'
            ) ?></span><br><code><?= htmlspecialchars($report['tax_code']) ?></code></div>
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars(
                (string) __('new_pdf.sub_status'),
                ENT_QUOTES,
                'UTF-8'
            ) ?></span><br>
                <span class="<?= sv_status_class($report['status']) ?>">
                    <?= sv_status_icon_markup($report['status']) ?>
                    <span class="sv-status-text"><?= htmlspecialchars(
                        sv_t_report_status((string) $report['status'])
                    ) ?></span>
                </span>
            </div>
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars(
                (string) __('new_pdf.sub_curve'),
                ENT_QUOTES,
                'UTF-8'
            ) ?></span><br><?php
            $c0 = (string) ($report['curve_type'] ?? '');
            echo $c0 === ''
                ? htmlspecialchars((string) __('common.dash'), ENT_QUOTES, 'UTF-8')
                : htmlspecialchars(sv_t_curve_filter($c0), ENT_QUOTES, 'UTF-8');
            ?></div>
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars(
                (string) __('new_pdf.sub_max'),
                ENT_QUOTES,
                'UTF-8'
            ) ?></span><br>
                <?php
                $reg = (string) ($report['cobb_max_region'] ?? '');
                $mx = $report['cobb_max_deg'] ?? null;
                echo ($mx !== null && $mx !== '')
                    ? htmlspecialchars(strtoupper($reg) . ' ' . number_format((float) $mx, 2) . '°')
                    : htmlspecialchars((string) __('common.dash'), ENT_QUOTES, 'UTF-8');
                ?>
            </div>
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars(
                (string) __('new_pdf.sub_tor'),
                ENT_QUOTES,
                'UTF-8'
            ) ?></span><br><?= sv_fmt_deg($report['cobb_thoracic_deg'] ?? null) ?> °</div>
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars(
                (string) __('new_pdf.sub_lum'),
                ENT_QUOTES,
                'UTF-8'
            ) ?></span><br><?= sv_fmt_deg($report['cobb_lumbar_deg'] ?? null) ?> °</div>
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars(
                (string) __('new_pdf.sub_hexam'),
                ENT_QUOTES,
                'UTF-8'
            ) ?></span><br><?php
                $hc = $report['height_cm'] ?? null;
                echo ($hc !== null && $hc !== '' && is_numeric($hc)) ? htmlspecialchars(number_format((float) $hc, 2)) . htmlspecialchars(
                    (string) __('patient.unit_cm'),
                    ENT_QUOTES,
                    'UTF-8'
                ) : htmlspecialchars((string) __('common.dash'), ENT_QUOTES, 'UTF-8');
            ?></div>
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars(
                (string) __('new_pdf.sub_wexam'),
                ENT_QUOTES,
                'UTF-8'
            ) ?></span><br><?php
                $wk = $report['weight_kg'] ?? null;
                echo ($wk !== null && $wk !== '' && is_numeric($wk)) ? htmlspecialchars(number_format((float) $wk, 2)) . htmlspecialchars(
                    (string) __('patient.unit_kg'),
                    ENT_QUOTES,
                    'UTF-8'
                ) : htmlspecialchars((string) __('common.dash'), ENT_QUOTES, 'UTF-8');
            ?></div>
        </div>
    </div>
</div>

<div class="sv-card">
    <div class="sv-card-header">
        <i class="fa-solid fa-pen-to-square"></i> <?= htmlspecialchars(
            (string) __('new_pdf.content'),
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </div>
    <div class="sv-card-body">
        <?php if ($report['status'] !== 'completed'): ?>
            <div class="alert alert-warning small">
                <?= (string) __('new_pdf.warn_incomplete', ['status' => htmlspecialchars(
                    sv_t_report_status((string) $report['status']),
                    ENT_QUOTES,
                    'UTF-8'
                )]) ?>
            </div>
        <?php endif; ?>
        <form method="post" action="new_pdf.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(sv_csrf_token()) ?>">
            <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">

            <div class="mb-3">
                <label class="form-label"><?= htmlspecialchars(
                    (string) __('new_pdf.label_title'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></label>
                <input class="form-control" name="title" maxlength="200"
                       placeholder="<?= htmlspecialchars($defaultPh, ENT_QUOTES, 'UTF-8') ?>"
                       value="<?= htmlspecialchars((string) ($_POST['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-text"><?= htmlspecialchars(
                    (string) __('new_pdf.hint_title'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></div>
            </div>

            <div class="mb-3">
                <label class="form-label"><?= htmlspecialchars(
                    (string) __('new_pdf.notes'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></label>
                <textarea class="form-control" name="notes" rows="10"
                          placeholder="<?= htmlspecialchars(
                              (string) __('new_pdf.notes_ph'),
                              ENT_QUOTES,
                              'UTF-8'
                          ) ?>"><?= htmlspecialchars((string) ($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                <div class="form-text"><?= htmlspecialchars(
                    (string) __('new_pdf.hint_notes'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></div>
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
                    <?= htmlspecialchars(
                        (string) __('new_pdf.include_img'),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </label>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-file-pdf fa-fw"></i> <?= htmlspecialchars(
                    (string) __('new_pdf.generate'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </button>
        </form>
    </div>
</div>
<?php sv_layout_end(); ?>

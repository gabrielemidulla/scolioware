<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/inference_internal.php';
require_once __DIR__ . '/inc/phi_log.php';
require_once __DIR__ . '/inc/Locale.php';
Locale::init();

sv_require_auth();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo htmlspecialchars((string) __('error.missing_pdf_id'), ENT_QUOTES, 'UTF-8');
    exit;
}

$stmt = db()->prepare(
    'SELECT id, patient_id, report_id, pdf_object_key, deleted_at
     FROM pdf_reports WHERE id = ? LIMIT 1'
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row || $row['deleted_at'] !== null) {
    http_response_code(404);
    echo htmlspecialchars((string) __('error.pdf_not_found'), ENT_QUOTES, 'UTF-8');
    exit;
}

$bytes = sv_inference_get_bytes('/internal/pdf-reports/' . (int) $id . '/file', 60);
if ($bytes === null || $bytes === '') {
    http_response_code(502);
    echo htmlspecialchars((string) __('error.pdf_stream'), ENT_QUOTES, 'UTF-8');
    exit;
}

sv_phi_log('download_pdf', (int) $row['patient_id'], (int) $row['report_id'], (int) $id);

$fname = 'scoliosoft-report-' . (int) $id . '.pdf';
header('Content-Type: application/pdf', true, 200);
header('Content-Disposition: inline; filename="' . $fname . '"', true);
header('Cache-Control: private, no-store', true);
header('X-Content-Type-Options: nosniff', true);
header('Pragma: no-cache', true);
echo $bytes;
exit;

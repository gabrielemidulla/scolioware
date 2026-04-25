<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/pdf.php';
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

$presigned = sv_pdf_presigned_url((int) $row['id']);
if ($presigned === null) {
    http_response_code(502);
    echo htmlspecialchars((string) __('error.pdf_download'), ENT_QUOTES, 'UTF-8');
    exit;
}

header('Location: ' . $presigned, true, 302);
exit;

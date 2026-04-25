<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/pdf.php';

sv_require_auth();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Missing PDF id';
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
    echo 'PDF report not found';
    exit;
}

$presigned = sv_pdf_presigned_url((int) $row['id']);
if ($presigned === null) {
    http_response_code(502);
    echo 'Could not resolve a download URL right now. Try again in a moment.';
    exit;
}

header('Location: ' . $presigned, true, 302);
exit;

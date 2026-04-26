<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/phi_log.php';
require_once __DIR__ . '/inc/inference_internal.php';
require_once __DIR__ . '/inc/Locale.php';
Locale::init();

sv_require_auth();

$rid = isset($_GET['report_id']) ? (int) $_GET['report_id'] : 0;
$kind = isset($_GET['kind']) ? strtolower(trim((string) $_GET['kind'])) : '';
if ($rid <= 0 || !in_array($kind, ['original', 'computed'], true)) {
    http_response_code(400);
    echo htmlspecialchars((string) __('error.report_image_param'), ENT_QUOTES, 'UTF-8');
    exit;
}

$stmt = db()->prepare('SELECT id, patient_id, status, original_object_key, computed_object_key FROM reports WHERE id = ?');
$stmt->execute([$rid]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    exit;
}

$pid = (int) $row['patient_id'];
sv_phi_log('view_report_image', $pid, $rid, null);

if ($kind === 'computed' && (string) $row['status'] !== 'completed') {
    http_response_code(404);
    exit;
}
if ($kind === 'original' && (string) ($row['original_object_key'] ?? '') === '') {
    http_response_code(404);
    exit;
}
if ($kind === 'computed' && (string) ($row['computed_object_key'] ?? '') === '') {
    http_response_code(404);
    exit;
}

$path = '/internal/reports/' . $rid . '/file?kind=' . rawurlencode($kind);
$bytes = sv_inference_get_bytes($path, 90);
if ($bytes === null || $bytes === '') {
    http_response_code(502);
    echo htmlspecialchars((string) __('error.report_image_fetch'), ENT_QUOTES, 'UTF-8');
    exit;
}

header('Content-Type: image/jpeg', true, 200);
header('Cache-Control: private, max-age=60', true);
header('X-Content-Type-Options: nosniff', true);
echo $bytes;
exit;

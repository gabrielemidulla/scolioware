<?php

declare(strict_types=1);

/**
 * AJAX endpoint: physician posts a corrected flat landmarks array; we forward it to the
 * inference service which recomputes the Cobb summary, re-renders the overlay image, and
 * inserts a row into `report_landmark_revisions` for the audit trail.
 *
 * The original model output is left untouched in `report_landmark_revisions` history.
 */

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/inference_internal.php';
require_once __DIR__ . '/inc/Locale.php';
require_once __DIR__ . '/inc/phi_log.php';

Locale::init();

header('Content-Type: application/json; charset=UTF-8');

function sv_landmarks_fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = sv_current_user();
if ($user === null) {
    sv_landmarks_fail(401, (string) __('edit.landmarks_err_auth'));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sv_landmarks_fail(405, 'Method Not Allowed');
}

$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    sv_landmarks_fail(400, (string) __('edit.landmarks_err_json'));
}

$tok = isset($body['csrf_token']) && is_string($body['csrf_token']) ? $body['csrf_token'] : '';
sv_session_start();
if ($tok === '' || empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $tok)) {
    sv_landmarks_fail(403, (string) __('error.csrf'));
}

$reportId = isset($body['report_id']) ? (int) $body['report_id'] : 0;
if ($reportId <= 0) {
    sv_landmarks_fail(400, (string) __('report.err_id'));
}

$lm = $body['landmarks'] ?? null;
if (!is_array($lm) || !array_is_list($lm) || count($lm) < 144) {
    sv_landmarks_fail(400, (string) __('edit.landmarks_err_len'));
}
$flat = [];
foreach ($lm as $v) {
    if (!is_int($v) && !is_float($v)) {
        sv_landmarks_fail(400, (string) __('edit.landmarks_err_len'));
    }
    if (!is_finite((float) $v)) {
        sv_landmarks_fail(400, (string) __('edit.landmarks_err_len'));
    }
    $flat[] = (float) $v;
}
if (count($flat) % 8 !== 0) {
    sv_landmarks_fail(400, (string) __('edit.landmarks_err_len'));
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, patient_id, status FROM reports WHERE id = ? LIMIT 1');
$stmt->execute([$reportId]);
$row = $stmt->fetch();
if (!$row) {
    sv_landmarks_fail(404, (string) __('report.err_nf'));
}
$status = (string) $row['status'];
if ($status !== 'completed' && $status !== 'failed') {
    sv_landmarks_fail(409, (string) __('edit.warn', ['status' => $status]));
}

$physId = isset($_SESSION['physician_id']) ? (int) $_SESSION['physician_id'] : 0;
$payload = [
    'landmarks' => $flat,
    'physician_id' => $physId > 0 ? $physId : null,
];

$res = sv_inference_post_json('/internal/reports/' . $reportId . '/recompute-landmarks', $payload, 180);
if ($res === null) {
    sv_landmarks_fail(502, (string) __('edit.landmarks_err_inf'));
}
$http = (int) ($res['__http'] ?? 0);
unset($res['__http']);
if ($http < 200 || $http >= 300) {
    $det = $res['detail'] ?? null;
    $msg = is_string($det) && $det !== ''
        ? $det
        : (string) json_encode($res, JSON_UNESCAPED_UNICODE);
    sv_landmarks_fail($http >= 400 && $http < 600 ? $http : 502, $msg);
}

sv_phi_log('recompute_landmarks', (int) $row['patient_id'], $reportId, null);

echo json_encode([
    'ok' => true,
    'report_id' => $reportId,
    'vertebra_count' => intdiv(count($flat), 8),
], JSON_UNESCAPED_UNICODE);
exit;

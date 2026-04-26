<?php

declare(strict_types=1);

/**
 * AJAX endpoint: ask the inference container to generate a fresh
 * LLM "first read" of the X-ray. The actual blocking Ollama call now
 * runs in the `inference-worker-llm` container (RQ `sv_llm` queue) so
 * this PHP handler is fast (just enqueue + return job descriptor).
 *
 * GET  -> returns { ok, draft, job } where:
 *           - draft: latest persisted draft row (or null)
 *           - job:   currently tracked RQ job state (or null) — has
 *                    fields { id, status: queued|started|finished|failed,
 *                    enqueued_at, error }. The dashboard polls this
 *                    endpoint every ~2 s while a job is in flight.
 * POST -> enqueues a NEW draft job, returns 202 with { ok, job }.
 *         Concurrent POSTs for the same report are deduplicated server-side.
 */

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/inference_internal.php';
require_once __DIR__ . '/inc/Locale.php';
require_once __DIR__ . '/inc/phi_log.php';

Locale::init();

header('Content-Type: application/json; charset=UTF-8');

function sv_llm_fail(int $code, string $msg): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = sv_current_user();
if ($user === null) {
    sv_llm_fail(401, (string) __('llm.err_auth'));
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $reportId = isset($_GET['report_id']) ? (int) $_GET['report_id'] : 0;
    if ($reportId <= 0) {
        sv_llm_fail(400, (string) __('report.err_id'));
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, patient_id, status FROM reports WHERE id = ? LIMIT 1');
    $stmt->execute([$reportId]);
    $row = $stmt->fetch();
    if (!$row) {
        sv_llm_fail(404, (string) __('report.err_nf'));
    }

    $res = sv_inference_get_json('/internal/reports/' . $reportId . '/llm-impression');
    if ($res === null) {
        sv_llm_fail(502, (string) __('llm.err_inf'));
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    sv_llm_fail(405, 'Method Not Allowed');
}

$raw = (string) file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    sv_llm_fail(400, (string) __('llm.err_body'));
}

$tok = isset($body['csrf_token']) && is_string($body['csrf_token']) ? $body['csrf_token'] : '';
sv_session_start();
if ($tok === '' || empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $tok)) {
    sv_llm_fail(403, (string) __('error.csrf'));
}

$reportId = isset($body['report_id']) ? (int) $body['report_id'] : 0;
if ($reportId <= 0) {
    sv_llm_fail(400, (string) __('report.err_id'));
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, patient_id, status FROM reports WHERE id = ? LIMIT 1');
$stmt->execute([$reportId]);
$row = $stmt->fetch();
if (!$row) {
    sv_llm_fail(404, (string) __('report.err_nf'));
}
$status = (string) $row['status'];
if ($status !== 'completed') {
    sv_llm_fail(409, (string) __('llm.err_status', ['status' => $status]));
}

$physId = isset($_SESSION['physician_id']) ? (int) $_SESSION['physician_id'] : 0;
$payload = [
    'physician_id' => $physId > 0 ? $physId : null,
];

// Enqueue is fast (~10–50 ms): inference returns 202 once the RQ job is queued.
// 30 s leaves headroom for transient Redis blips without holding the PHP-FPM
// worker on a long inference call.
$res = sv_inference_post_json('/internal/reports/' . $reportId . '/llm-impression', $payload, 30);
if ($res === null) {
    sv_llm_fail(502, (string) __('llm.err_inf'));
}
$http = (int) ($res['__http'] ?? 0);
unset($res['__http']);
if ($http < 200 || $http >= 300) {
    $det = $res['detail'] ?? null;
    $msg = is_string($det) && $det !== ''
        ? $det
        : (string) json_encode($res, JSON_UNESCAPED_UNICODE);
    sv_llm_fail($http >= 400 && $http < 600 ? $http : 502, $msg);
}

sv_phi_log('llm_draft_impression_enqueued', (int) $row['patient_id'], $reportId, null);

http_response_code($http === 202 ? 202 : 200);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
exit;

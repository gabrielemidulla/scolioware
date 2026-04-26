<?php

declare(strict_types=1);

namespace App\Api;

use App\Audit\PhiLogger;
use App\Infrastructure\Database;
use App\Exception\ApiJsonTerminator;
use App\Infrastructure\InferenceInternalGateway;
use Symfony\Component\HttpFoundation\Request;

final class RecomputeLandmarksHandler
{
    private function fail(int $code, string $msg): never
    {
        throw new ApiJsonTerminator(
            $code,
            json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE)
        );
    }

    public function handle(Request $request): void
    {
        $user = sw_current_user();
        if ($user === null) {
            $this->fail(401, (string) __('edit.landmarks_err_auth'));
        }

        if ($request->getMethod() !== 'POST') {
            $this->fail(405, 'Method Not Allowed');
        }

        $raw = $request->getContent();
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $this->fail(400, (string) __('edit.landmarks_err_json'));
        }

        $tok = isset($body['csrf_token']) && is_string($body['csrf_token']) ? $body['csrf_token'] : '';
        if (!sw_csrf_check_token($tok)) {
            $this->fail(403, (string) __('error.csrf'));
        }

        $reportId = isset($body['report_id']) ? (int) $body['report_id'] : 0;
        if ($reportId <= 0) {
            $this->fail(400, (string) __('report.err_id'));
        }

        $lm = $body['landmarks'] ?? null;
        if (!is_array($lm) || !array_is_list($lm) || count($lm) < 136) {
            $this->fail(400, (string) __('edit.landmarks_err_len'));
        }
        $flat = [];
        foreach ($lm as $v) {
            if (!is_int($v) && !is_float($v)) {
                $this->fail(400, (string) __('edit.landmarks_err_len'));
            }
            if (!is_finite((float) $v)) {
                $this->fail(400, (string) __('edit.landmarks_err_len'));
            }
            $flat[] = (float) $v;
        }
        if (count($flat) % 8 !== 0) {
            $this->fail(400, (string) __('edit.landmarks_err_len'));
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, patient_id, status FROM reports WHERE id = ? LIMIT 1');
        $stmt->execute([$reportId]);
        $row = $stmt->fetch();
        if (!$row) {
            $this->fail(404, (string) __('report.err_nf'));
        }
        $status = (string) $row['status'];
        if ($status !== 'completed' && $status !== 'failed') {
            $this->fail(409, (string) __('edit.warn', ['status' => $status]));
        }

        $physId = isset($_SESSION['physician_id']) ? (int) $_SESSION['physician_id'] : 0;
        $payload = [
            'landmarks' => $flat,
            'physician_id' => $physId > 0 ? $physId : null,
        ];

        $result = InferenceInternalGateway::instance()->postJson(
            '/internal/reports/' . $reportId . '/recompute-landmarks',
            $payload,
            180.0
        );
        if ($result === null) {
            $this->fail(502, (string) __('edit.landmarks_err_inf'));
        }
        $http = $result->httpStatus;
        $res = $result->body;
        if ($http < 200 || $http >= 300) {
            $det = $res['detail'] ?? null;
            $msg = is_string($det) && $det !== ''
                ? $det
                : (string) json_encode($res, JSON_UNESCAPED_UNICODE);
            $this->fail($http >= 400 && $http < 600 ? $http : 502, $msg);
        }

        PhiLogger::append('recompute_landmarks', (int) $row['patient_id'], $reportId, null);

        throw new ApiJsonTerminator(
            200,
            json_encode([
                'ok' => true,
                'report_id' => $reportId,
                'vertebra_count' => intdiv(count($flat), 8),
            ], JSON_UNESCAPED_UNICODE)
        );
    }
}

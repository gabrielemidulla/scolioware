<?php

declare(strict_types=1);

namespace App\Api;

use App\Audit\PhiLogger;
use App\Infrastructure\Database;
use App\Exception\ApiJsonTerminator;
use App\Infrastructure\InferenceInternalGateway;
use Symfony\Component\HttpFoundation\Request;

final class GenerateDraftImpressionHandler
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
            $this->fail(401, (string) __('llm.err_auth'));
        }

        $method = $request->getMethod();

        if ($method === 'GET') {
            $reportId = (int) $request->query->get('report_id', 0);
            if ($reportId <= 0) {
                $this->fail(400, (string) __('report.err_id'));
            }

            $pdo = Database::pdo();
            $stmt = $pdo->prepare('SELECT id, patient_id, status FROM reports WHERE id = ? LIMIT 1');
            $stmt->execute([$reportId]);
            $row = $stmt->fetch();
            if (!$row) {
                $this->fail(404, (string) __('report.err_nf'));
            }

            $res = InferenceInternalGateway::instance()->getJson(
                '/internal/reports/' . $reportId . '/llm-impression',
                20.0
            );
            if ($res === null) {
                $this->fail(502, (string) __('llm.err_inf'));
            }
            throw new ApiJsonTerminator(200, json_encode($res, JSON_UNESCAPED_UNICODE));
        }

        if ($method !== 'POST') {
            $this->fail(405, 'Method Not Allowed');
        }

        $raw = $request->getContent();
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $this->fail(400, (string) __('llm.err_body'));
        }

        $tok = isset($body['csrf_token']) && is_string($body['csrf_token']) ? $body['csrf_token'] : '';
        if (!sw_csrf_check_token($tok)) {
            $this->fail(403, (string) __('error.csrf'));
        }

        $reportId = isset($body['report_id']) ? (int) $body['report_id'] : 0;
        if ($reportId <= 0) {
            $this->fail(400, (string) __('report.err_id'));
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, patient_id, status FROM reports WHERE id = ? LIMIT 1');
        $stmt->execute([$reportId]);
        $row = $stmt->fetch();
        if (!$row) {
            $this->fail(404, (string) __('report.err_nf'));
        }
        $status = (string) $row['status'];
        if ($status !== 'completed') {
            $this->fail(409, (string) __('llm.err_status', ['status' => $status]));
        }

        $physId = isset($_SESSION['physician_id']) ? (int) $_SESSION['physician_id'] : 0;
        $payload = [
            'physician_id' => $physId > 0 ? $physId : null,
        ];

        $result = InferenceInternalGateway::instance()->postJson(
            '/internal/reports/' . $reportId . '/llm-impression',
            $payload,
            30.0
        );
        if ($result === null) {
            $this->fail(502, (string) __('llm.err_inf'));
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

        PhiLogger::append('llm_draft_impression_enqueued', (int) $row['patient_id'], $reportId, null);

        throw new ApiJsonTerminator(
            $http === 202 ? 202 : 200,
            json_encode($res, JSON_UNESCAPED_UNICODE)
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Audit;

/** PHI access audit rows. */
final class PhiLogger
{
    public static function append(
        string $action,
        ?int $patientId = null,
        ?int $reportId = null,
        ?int $pdfReportId = null
    ): void {
        if (!function_exists('sw_current_user') || !class_exists(\App\Infrastructure\Database::class)) {
            return;
        }
        $u = sw_current_user();
        $pid = (int) ($u['id'] ?? 0);
        if ($pid <= 0) {
            $pid = null;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ip = is_string($ip) ? (strlen($ip) > 45 ? substr($ip, 0, 45) : $ip) : null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $ua = is_string($ua) ? (strlen($ua) > 500 ? substr($ua, 0, 500) : $ua) : null;
        $path = $_SERVER['REQUEST_URI'] ?? null;
        if (is_string($path) && strlen($path) > 255) {
            $path = substr($path, 0, 252) . '...';
        }
        $path = is_string($path) ? $path : null;
        $action = strlen($action) > 32 ? substr($action, 0, 32) : $action;

        try {
            $stmt = \App\Infrastructure\Database::pdo()->prepare(
                'INSERT INTO phi_access_log
                 (physician_id, action, patient_id, report_id, pdf_report_id, ip, user_agent, `path`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute(
                [
                    $pid,
                    $action,
                    $patientId,
                    $reportId,
                    $pdfReportId,
                    $ip,
                    $ua,
                    $path,
                ]
            );
        } catch (\Throwable $e) {
            error_log('PhiLogger: ' . $e->getMessage());
        }
    }
}

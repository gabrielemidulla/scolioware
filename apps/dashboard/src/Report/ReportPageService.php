<?php

declare(strict_types=1);

namespace App\Report;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final class ReportPageService
{
    private const PDF_PER_PAGE = 5;

    public function __construct(private readonly Connection $conn)
    {
    }

    /** @return array<string, mixed>|null */
    public function fetchReportRow(int $id): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->conn->fetchAssociative(
            'SELECT r.*, p.first_name, p.last_name, p.tax_code
             FROM reports r JOIN patients p ON p.id = r.patient_id WHERE r.id = ?',
            [$id]
        );

        return $row !== false ? $row : null;
    }

    /**
     * @return array{errors: list<string>, hDb: ?float, wDb: ?float}
     */
    public function parseVitalsInput(string $hRaw, string $wRaw): array
    {
        $vitalsErrors = [];
        $hDb = null;
        $wDb = null;

        $hRaw = trim($hRaw);
        $wRaw = trim($wRaw);

        if ($hRaw !== '') {
            $hs = str_replace(',', '.', $hRaw);
            if (!preg_match('/^\d+(\.\d+)?$/', $hs)) {
                $vitalsErrors[] = (string) __('report.vitals_error_h');
            } else {
                $hv = (float) $hs;
                if ($hv < 50.0 || $hv > 250.0) {
                    $vitalsErrors[] = (string) __('report.vitals_error_h_range');
                } else {
                    $hDb = round($hv, 2);
                }
            }
        }
        if ($wRaw !== '') {
            $ws = str_replace(',', '.', $wRaw);
            if (!preg_match('/^\d+(\.\d+)?$/', $ws)) {
                $vitalsErrors[] = (string) __('report.vitals_error_w');
            } else {
                $wv = (float) $ws;
                if ($wv < 10.0 || $wv > 350.0) {
                    $vitalsErrors[] = (string) __('report.vitals_error_w_range');
                } else {
                    $wDb = round($wv, 2);
                }
            }
        }

        return ['errors' => $vitalsErrors, 'hDb' => $hDb, 'wDb' => $wDb];
    }

    public function applyVitalsUpdate(int $reportId, ?float $hDb, ?float $wDb): int
    {
        $this->conn->update('reports', [
            'height_cm' => $hDb,
            'weight_kg' => $wDb,
        ], ['id' => $reportId]);
        $rep = $this->conn->fetchAssociative('SELECT patient_id FROM reports WHERE id = ?', [$reportId]);

        return (int) ($rep['patient_id'] ?? 0);
    }

    public function refreshPatientLastVitals(int $patientId): void
    {
        $r = $this->conn->fetchAssociative(
            'SELECT height_cm, weight_kg FROM reports
         WHERE patient_id = ? AND height_cm IS NOT NULL AND weight_kg IS NOT NULL
         ORDER BY created_at DESC, id DESC
         LIMIT 1',
            [$patientId]
        );
        if ($r !== false) {
            $this->conn->update('patients', [
                'last_height_cm' => (float) $r['height_cm'],
                'last_weight_kg' => (float) $r['weight_kg'],
            ], ['id' => $patientId]);
        } else {
            $this->conn->executeStatement(
                'UPDATE patients SET last_height_cm = NULL, last_weight_kg = NULL WHERE id = ?',
                [$patientId]
            );
        }
    }

    /**
     * @return array{
     *     pdfReports: list<array<string, mixed>>,
     *     pdfTotalCount: int,
     *     pdfPage: int,
     *     pdfTotalPages: int
     * }
     */
    public function loadPdfSection(Request $request, int $reportId): array
    {
        $pdfPerPage = self::PDF_PER_PAGE;
        $pdfTotalCount = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM pdf_reports WHERE report_id = ? AND deleted_at IS NULL',
            [$reportId]
        );
        $pdfTotalPages = max(1, (int) ceil($pdfTotalCount / $pdfPerPage));
        $pdfPage = max(1, (int) $request->query->get('pdf_page', 1));
        if ($pdfPage > $pdfTotalPages) {
            $pdfPage = $pdfTotalPages;
        }
        $pdfOffset = ($pdfPage - 1) * $pdfPerPage;

        /** @var list<array<string, mixed>> $pdfReports */
        $pdfReports = $this->conn->fetchAllAssociative(
            'SELECT pr.id, pr.title, pr.notes, pr.created_at, pr.physician_username,
            ph.display_name AS physician_display_name
     FROM pdf_reports pr
     LEFT JOIN physicians ph ON ph.id = pr.physician_id AND ph.deleted_at IS NULL
     WHERE pr.report_id = ? AND pr.deleted_at IS NULL
     ORDER BY pr.created_at DESC, pr.id DESC
     LIMIT ' . $pdfPerPage . ' OFFSET ' . $pdfOffset,
            [$reportId]
        );

        return [
            'pdfReports' => $pdfReports,
            'pdfTotalCount' => $pdfTotalCount,
            'pdfPage' => $pdfPage,
            'pdfTotalPages' => $pdfTotalPages,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{showVitals: bool, vitalsText: string, vitalsFormH: string, vitalsFormW: string}
     */
    public function vitalsDisplayState(array $row, array $vitalsErrors, Request $request): array
    {
        $rvH = $row['height_cm'] ?? null;
        $rvW = $row['weight_kg'] ?? null;
        $showVitals = $rvH !== null && $rvH !== '' && is_numeric($rvH) && $rvW !== null && $rvW !== '' && is_numeric($rvW);
        $uCm = (string) __('patient.unit_cm');
        $uKg = (string) __('patient.unit_kg');
        $vitalsText = $showVitals
            ? number_format((float) $rvH, 2) . $uCm . ', ' . number_format((float) $rvW, 2) . $uKg
            : (string) __('common.dash');

        if ($vitalsErrors !== []) {
            $vitalsFormH = (string) $request->request->get('height_cm', '');
            $vitalsFormW = (string) $request->request->get('weight_kg', '');
        } else {
            $vitalsFormH = ($rvH !== null && $rvH !== '' && is_numeric($rvH)) ? sprintf('%.2f', (float) $rvH) : '';
            $vitalsFormW = ($rvW !== null && $rvW !== '' && is_numeric($rvW)) ? sprintf('%.2f', (float) $rvW) : '';
        }

        return [
            'showVitals' => $showVitals,
            'vitalsText' => $vitalsText,
            'vitalsFormH' => $vitalsFormH,
            'vitalsFormW' => $vitalsFormW,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public function buildPdfCountLine(int $pdfTotalCount, int $pdfPage, int $pdfTotalPages): string
    {
        $pdfCountLine = '(' . $pdfTotalCount . ' · ' . (string) __('patient.reports_sub');
        if ($pdfTotalPages > 1) {
            $pdfCountLine .= ' · ' . (string) __('report.pdf_sub_page', ['cur' => $pdfPage, 'max' => $pdfTotalPages]);
        }
        $pdfCountLine .= ')';

        return $pdfCountLine;
    }
}

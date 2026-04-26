<?php

declare(strict_types=1);

namespace App\Patient;

use App\Report\ReportListFilter;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final class PatientPageService
{
    private const PER_PAGE = 20;

    public function __construct(private readonly Connection $conn)
    {
    }

    /** @return array<string, mixed>|null */
    public function fetchPatient(int $id): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->conn->fetchAssociative('SELECT * FROM patients WHERE id = ?', [$id]);

        return $row !== false ? $row : null;
    }

    public function countAllReportsForPatient(int $patientId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM reports WHERE patient_id = ?', [$patientId]);
    }

    public function deletePatient(int $id): void
    {
        $this->conn->delete('patients', ['id' => $id]);
    }

    /**
     * @return array{
     *     f: array<string, mixed>,
     *     reports: list<array<string, mixed>>,
     *     totalReports: int,
     *     page: int,
     *     perPage: int,
     *     totalPages: int,
     *     from: int,
     *     to: int,
     *     chartRows: list<array<string, mixed>>,
     *     chartData: array<string, mixed>
     * }
     */
    public function loadReportsSection(Request $request, int $patientId): array
    {
        $f = ReportListFilter::parseFromRequest($request);
        $perPage = self::PER_PAGE;
        $page = max(1, (int) $request->query->get('page', 1));

        $where = ['r.patient_id = ?'];
        $params = [$patientId];
        ReportListFilter::append($where, $params, $f);
        $whereSql = implode(' AND ', $where);

        $totalReports = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM reports r WHERE {$whereSql}", $params);
        $totalPages = max(1, (int) ceil($totalReports / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $listSql = "SELECT r.id, r.status, r.curve_type, r.created_at,
               r.height_cm, r.weight_kg,
               r.cobb_thoracic_deg, r.cobb_lumbar_deg, r.cobb_max_deg, r.cobb_max_region,
               r.error_message
        FROM reports r
        WHERE {$whereSql}
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT {$perPage} OFFSET {$offset}";

        /** @var list<array<string, mixed>> $reports */
        $reports = $this->conn->fetchAllAssociative($listSql, $params);

        /** @var list<array<string, mixed>> $chartRows */
        $chartRows = $this->conn->fetchAllAssociative(
            "SELECT id, created_at, status,
            cobb_pt_deg, cobb_mt_deg, cobb_tl_deg,
            cobb_thoracic_deg, cobb_lumbar_deg, cobb_max_deg, cobb_max_region,
            height_cm, weight_kg
     FROM reports
     WHERE patient_id = ? AND status = 'completed'
     ORDER BY created_at ASC, id ASC",
            [$patientId]
        );

        $chartData = [
            'labels' => [],
            'rids' => [],
            'cobb_pt' => [],
            'cobb_mt' => [],
            'cobb_tl' => [],
            'cobb_max' => [],
            'cobb_max_region' => [],
            'height_cm' => [],
            'weight_kg' => [],
        ];
        foreach ($chartRows as $cr) {
            $chartData['labels'][] = (string) $cr['created_at'];
            $chartData['rids'][] = (int) $cr['id'];
            $chartData['cobb_pt'][] = isset($cr['cobb_pt_deg']) && is_numeric($cr['cobb_pt_deg']) ? (float) $cr['cobb_pt_deg'] : null;
            $chartData['cobb_mt'][] = isset($cr['cobb_mt_deg']) && is_numeric($cr['cobb_mt_deg']) ? (float) $cr['cobb_mt_deg'] : null;
            $chartData['cobb_tl'][] = isset($cr['cobb_tl_deg']) && is_numeric($cr['cobb_tl_deg']) ? (float) $cr['cobb_tl_deg'] : null;
            $chartData['cobb_max'][] = isset($cr['cobb_max_deg']) && is_numeric($cr['cobb_max_deg']) ? (float) $cr['cobb_max_deg'] : null;
            $chartData['cobb_max_region'][] = $cr['cobb_max_region'] !== null ? (string) $cr['cobb_max_region'] : '';
            $chartData['height_cm'][] = isset($cr['height_cm']) && is_numeric($cr['height_cm']) ? (float) $cr['height_cm'] : null;
            $chartData['weight_kg'][] = isset($cr['weight_kg']) && is_numeric($cr['weight_kg']) ? (float) $cr['weight_kg'] : null;
        }

        $from = $totalReports === 0 ? 0 : $offset + 1;
        $to = min($offset + count($reports), $totalReports);

        return [
            'f' => $f,
            'reports' => $reports,
            'totalReports' => $totalReports,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'from' => $from,
            'to' => $to,
            'chartRows' => $chartRows,
            'chartData' => $chartData,
        ];
    }
}

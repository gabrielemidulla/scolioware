<?php

declare(strict_types=1);

namespace App\Queue;

use App\Report\ReportListFilter;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final class QueuePageService
{
    private const PER_PAGE = 20;

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @return array{
     *     f: array<string, mixed>,
     *     rows: list<array<string, mixed>>,
     *     totalReports: int,
     *     page: int,
     *     perPage: int,
     *     totalPages: int,
     *     from: int,
     *     to: int,
     *     allPatients: list<array<string, mixed>>,
     *     patientId: int,
     *     tax: string
     * }
     */
    public function load(Request $request): array
    {
        $patientId = (int) $request->query->get('patient_id', 0);
        $tax = trim((string) $request->query->get('tax', ''));
        $f = ReportListFilter::parseFromRequest($request);

        $where = ['1=1'];
        $params = [];
        ReportListFilter::append($where, $params, $f);

        if ($patientId > 0) {
            $where[] = 'r.patient_id = ?';
            $params[] = $patientId;
        }

        if ($tax !== '') {
            $where[] = 'p.tax_code LIKE ?';
            $params[] = '%' . $this->likeEscape($tax) . '%';
        }

        $whereSql = implode(' AND ', $where);

        $totalReports = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM reports r JOIN patients p ON p.id = r.patient_id WHERE {$whereSql}",
            $params
        );

        $perPage = self::PER_PAGE;
        $page = max(1, (int) $request->query->get('page', 1));
        $totalPages = max(1, (int) ceil($totalReports / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $selectSql = "SELECT r.id, r.patient_id, r.status, r.error_message, r.curve_type, r.created_at,
               r.height_cm, r.weight_kg,
               r.cobb_thoracic_deg, r.cobb_lumbar_deg, r.cobb_max_deg, r.cobb_max_region,
               p.first_name, p.last_name, p.tax_code
        FROM reports r
        JOIN patients p ON p.id = r.patient_id
        WHERE {$whereSql}
        ORDER BY r.id DESC
        LIMIT {$perPage} OFFSET {$offset}";

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative($selectSql, $params);

        $from = $totalReports === 0 ? 0 : $offset + 1;
        $to = min($offset + count($rows), $totalReports);

        /** @var list<array<string, mixed>> $allPatients */
        $allPatients = $this->conn->fetchAllAssociative(
            'SELECT id, first_name, last_name, tax_code FROM patients ORDER BY last_name ASC, first_name ASC'
        );

        return [
            'f' => $f,
            'rows' => $rows,
            'totalReports' => $totalReports,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'from' => $from,
            'to' => $to,
            'allPatients' => $allPatients,
            'patientId' => $patientId,
            'tax' => $tax,
        ];
    }

    private function likeEscape(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }
}

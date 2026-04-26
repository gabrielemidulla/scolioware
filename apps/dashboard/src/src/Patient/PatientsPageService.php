<?php

declare(strict_types=1);

namespace App\Patient;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final class PatientsPageService
{
    private const PER_PAGE = 10;

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     totalPatients: int,
     *     page: int,
     *     perPage: int,
     *     totalPages: int,
     *     from: int,
     *     to: int,
     *     pHtMin: ?float,
     *     pHtMax: ?float,
     *     pWtMin: ?float,
     *     pWtMax: ?float
     * }
     */
    public function loadList(Request $request): array
    {
        $pHtMin = $this->floatOpt($request, 'p_ht_min');
        $pHtMax = $this->floatOpt($request, 'p_ht_max');
        if ($pHtMin !== null && $pHtMax !== null && $pHtMin > $pHtMax) {
            [$pHtMin, $pHtMax] = [$pHtMax, $pHtMin];
        }
        $pWtMin = $this->floatOpt($request, 'p_wt_min');
        $pWtMax = $this->floatOpt($request, 'p_wt_max');
        if ($pWtMin !== null && $pWtMax !== null && $pWtMin > $pWtMax) {
            [$pWtMin, $pWtMax] = [$pWtMax, $pWtMin];
        }

        $whereP = ['1=1'];
        $paramsP = [];
        if ($pHtMin !== null) {
            $whereP[] = 'last_height_cm IS NOT NULL AND last_height_cm >= ?';
            $paramsP[] = $pHtMin;
        }
        if ($pHtMax !== null) {
            $whereP[] = 'last_height_cm IS NOT NULL AND last_height_cm <= ?';
            $paramsP[] = $pHtMax;
        }
        if ($pWtMin !== null) {
            $whereP[] = 'last_weight_kg IS NOT NULL AND last_weight_kg >= ?';
            $paramsP[] = $pWtMin;
        }
        if ($pWtMax !== null) {
            $whereP[] = 'last_weight_kg IS NOT NULL AND last_weight_kg <= ?';
            $paramsP[] = $pWtMax;
        }
        $whereSqlP = implode(' AND ', $whereP);

        $perPage = self::PER_PAGE;
        $page = max(1, (int) $request->query->get('page', 1));
        $totalPatients = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM patients WHERE {$whereSqlP}", $paramsP);
        $totalPages = max(1, (int) ceil($totalPatients / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            "SELECT * FROM patients WHERE {$whereSqlP} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            $paramsP
        );

        $from = $totalPatients === 0 ? 0 : $offset + 1;
        $to = min($offset + count($rows), $totalPatients);

        return [
            'rows' => $rows,
            'totalPatients' => $totalPatients,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'from' => $from,
            'to' => $to,
            'pHtMin' => $pHtMin,
            'pHtMax' => $pHtMax,
            'pWtMin' => $pWtMin,
            'pWtMax' => $pWtMax,
        ];
    }

    /**
     * @throws \RuntimeException validation / DB errors surfaced to UI
     */
    public function createPatient(Request $request): int
    {
        $first = trim((string) $request->request->get('first_name', ''));
        $last = trim((string) $request->request->get('last_name', ''));
        $tax = strtoupper(trim((string) $request->request->get('tax_code', '')));
        $birthRaw = trim((string) $request->request->get('birth_date', ''));
        $gender = (string) $request->request->get('gender', 'male');
        $allowedGender = ['male', 'female', 'non_binary'];

        if ($first === '' || $last === '') {
            throw new \RuntimeException((string) __('patients.err_name_required'));
        }
        if (mb_strlen($first) > 191 || mb_strlen($last) > 191) {
            throw new \RuntimeException((string) __('patients.err_name_len'));
        }
        if ($tax === '') {
            throw new \RuntimeException((string) __('patients.err_tax_required'));
        }
        if (mb_strlen($tax) > 32) {
            throw new \RuntimeException((string) __('patients.err_tax_len'));
        }
        if (!in_array($gender, $allowedGender, true)) {
            throw new \RuntimeException((string) __('patients.err_gender'));
        }
        if ($birthRaw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthRaw)) {
            throw new \RuntimeException((string) __('patients.err_birth'));
        }
        $parts = array_map('intval', explode('-', $birthRaw));
        if (count($parts) !== 3 || !checkdate($parts[1], $parts[2], $parts[0])) {
            throw new \RuntimeException((string) __('patients.err_birth'));
        }

        $this->conn->insert('patients', [
            'first_name' => $first,
            'last_name' => $last,
            'tax_code' => $tax,
            'birth_date' => $birthRaw,
            'gender' => $gender,
        ]);
        $newId = (int) $this->conn->lastInsertId();
        if ($newId <= 0) {
            throw new \RuntimeException((string) __('patients.err_create'));
        }

        return $newId;
    }

    private function floatOpt(Request $request, string $name): ?float
    {
        if (!$request->query->has($name)) {
            return null;
        }
        $s = trim((string) $request->query->get($name));
        if ($s === '') {
            return null;
        }
        if (!is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }
}

<?php

declare(strict_types=1);

namespace App\Report;

use Symfony\Component\HttpFoundation\Request;

/**
 * Shared report list filters for patient report table and global queue.
 */
final class ReportListFilter
{
    /**
     * @return array{
     *     status: string,
     *     curve: string,
     *     torMin: ?float,
     *     torMax: ?float,
     *     lumMin: ?float,
     *     lumMax: ?float,
     *     cobbMin: ?float,
     *     cobbMax: ?float,
     *     hCmMin: ?float,
     *     hCmMax: ?float,
     *     wKgMin: ?float,
     *     wKgMax: ?float,
     *     createdFrom: string,
     *     createdTo: string,
     *     ridExact: string,
     *     ridExactInt: int,
     *     ridMin: int,
     *     ridMax: int
     * }
     */
    public static function parseFromRequest(Request $request): array
    {
        /** @var array<string, mixed> $q */
        $q = $request->query->all();

        return self::parse($q);
    }

    /**
     * @param array<string, mixed> $q
     *
     * @return array{
     *     status: string,
     *     curve: string,
     *     torMin: ?float,
     *     torMax: ?float,
     *     lumMin: ?float,
     *     lumMax: ?float,
     *     cobbMin: ?float,
     *     cobbMax: ?float,
     *     hCmMin: ?float,
     *     hCmMax: ?float,
     *     wKgMin: ?float,
     *     wKgMax: ?float,
     *     createdFrom: string,
     *     createdTo: string,
     *     ridExact: string,
     *     ridExactInt: int,
     *     ridMin: int,
     *     ridMax: int
     * }
     */
    public static function parse(array $q): array
    {
        $allowedStatus = ['pending', 'processing', 'completed', 'failed'];
        $status = self::strOpt($q, 'status');
        if ($status !== '' && !in_array($status, $allowedStatus, true)) {
            $status = '';
        }
        $allowedCurve = ['', 'C', 'S', 'unspecified'];
        $curve = self::strOpt($q, 'curve');
        if (!in_array($curve, $allowedCurve, true)) {
            $curve = '';
        }

        $torMin = self::floatOpt($q, 'tor_min');
        $torMax = self::floatOpt($q, 'tor_max');
        if ($torMin !== null && $torMax !== null && $torMin > $torMax) {
            [$torMin, $torMax] = [$torMax, $torMin];
        }

        $lumMin = self::floatOpt($q, 'lum_min');
        $lumMax = self::floatOpt($q, 'lum_max');
        if ($lumMin !== null && $lumMax !== null && $lumMin > $lumMax) {
            [$lumMin, $lumMax] = [$lumMax, $lumMin];
        }

        $cobbMin = self::floatOpt($q, 'cobb_min');
        $cobbMax = self::floatOpt($q, 'cobb_max');
        if ($cobbMin !== null && $cobbMax !== null && $cobbMin > $cobbMax) {
            [$cobbMin, $cobbMax] = [$cobbMax, $cobbMin];
        }

        $hCmMin = self::floatOpt($q, 'h_cm_min');
        $hCmMax = self::floatOpt($q, 'h_cm_max');
        if ($hCmMin !== null && $hCmMax !== null && $hCmMin > $hCmMax) {
            [$hCmMin, $hCmMax] = [$hCmMax, $hCmMin];
        }

        $wKgMin = self::floatOpt($q, 'w_kg_min');
        $wKgMax = self::floatOpt($q, 'w_kg_max');
        if ($wKgMin !== null && $wKgMax !== null && $wKgMin > $wKgMax) {
            [$wKgMin, $wKgMax] = [$wKgMax, $wKgMin];
        }

        $createdFrom = self::strOpt($q, 'created_from');
        $createdTo = self::strOpt($q, 'created_to');
        if ($createdFrom !== '' && $createdTo !== '' && $createdFrom > $createdTo) {
            [$createdFrom, $createdTo] = [$createdTo, $createdFrom];
        }
        if ($createdFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdFrom)) {
            $createdFrom = '';
        }
        if ($createdTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdTo)) {
            $createdTo = '';
        }

        $ridExact = isset($q['rid']) ? trim((string) $q['rid']) : '';
        $ridExactInt = $ridExact !== '' && ctype_digit($ridExact) ? (int) $ridExact : 0;

        $ridMin = self::intFrom($q, 'rid_min', 0, 0);
        $ridMax = self::intFrom($q, 'rid_max', 0, 0);
        if ($ridExactInt <= 0 && $ridMin > 0 && $ridMax > 0 && $ridMin > $ridMax) {
            [$ridMin, $ridMax] = [$ridMax, $ridMin];
        }

        return [
            'status' => $status,
            'curve' => $curve,
            'torMin' => $torMin,
            'torMax' => $torMax,
            'lumMin' => $lumMin,
            'lumMax' => $lumMax,
            'cobbMin' => $cobbMin,
            'cobbMax' => $cobbMax,
            'hCmMin' => $hCmMin,
            'hCmMax' => $hCmMax,
            'wKgMin' => $wKgMin,
            'wKgMax' => $wKgMax,
            'createdFrom' => $createdFrom,
            'createdTo' => $createdTo,
            'ridExact' => $ridExact,
            'ridExactInt' => $ridExactInt,
            'ridMin' => $ridMin,
            'ridMax' => $ridMax,
        ];
    }

    /**
     * @param list<string>         $where
     * @param list<mixed>          $params
     * @param array<string, mixed> $f
     */
    public static function append(array &$where, array &$params, array $f): void
    {
        $ridExactInt = (int) ($f['ridExactInt'] ?? 0);
        $ridMin = (int) ($f['ridMin'] ?? 0);
        $ridMax = (int) ($f['ridMax'] ?? 0);
        if ($ridExactInt > 0) {
            $where[] = 'r.id = ?';
            $params[] = $ridExactInt;
        } else {
            if ($ridMin > 0) {
                $where[] = 'r.id >= ?';
                $params[] = $ridMin;
            }
            if ($ridMax > 0) {
                $where[] = 'r.id <= ?';
                $params[] = $ridMax;
            }
        }

        $status = (string) ($f['status'] ?? '');
        if ($status !== '') {
            $where[] = 'r.status = ?';
            $params[] = $status;
        }

        $curve = (string) ($f['curve'] ?? '');
        if ($curve === 'C' || $curve === 'S') {
            $where[] = 'r.curve_type = ?';
            $params[] = $curve;
        } elseif ($curve === 'unspecified') {
            $where[] = 'r.curve_type IS NULL';
        }

        foreach (
            [
                ['torMin', 'torMax', 'r.cobb_thoracic_deg'],
                ['lumMin', 'lumMax', 'r.cobb_lumbar_deg'],
                ['cobbMin', 'cobbMax', 'r.cobb_max_deg'],
                ['hCmMin', 'hCmMax', 'r.height_cm'],
                ['wKgMin', 'wKgMax', 'r.weight_kg'],
            ] as [$kMin, $kMax, $col]
        ) {
            $mn = $f[$kMin] ?? null;
            $mx = $f[$kMax] ?? null;
            if ($mn !== null) {
                $where[] = "({$col} IS NOT NULL AND {$col} >= ?)";
                $params[] = $mn;
            }
            if ($mx !== null) {
                $where[] = "({$col} IS NOT NULL AND {$col} <= ?)";
                $params[] = $mx;
            }
        }

        $createdFrom = (string) ($f['createdFrom'] ?? '');
        $createdTo = (string) ($f['createdTo'] ?? '');
        if ($createdFrom !== '') {
            $where[] = 'r.created_at >= ?';
            $params[] = $createdFrom . ' 00:00:00';
        }
        if ($createdTo !== '') {
            $where[] = 'r.created_at <= ?';
            $params[] = $createdTo . ' 23:59:59';
        }
    }

    /** @param array<string, mixed> $q */
    private static function strOpt(array $q, string $name): string
    {
        if (!isset($q[$name])) {
            return '';
        }

        return trim((string) $q[$name]);
    }

    /** @param array<string, mixed> $q */
    private static function floatOpt(array $q, string $name): ?float
    {
        if (!isset($q[$name])) {
            return null;
        }
        $s = trim((string) $q[$name]);
        if ($s === '') {
            return null;
        }
        if (!is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }

    /** @param array<string, mixed> $q */
    private static function intFrom(array $q, string $name, int $default, int $min): int
    {
        if (!isset($q[$name])) {
            return $default;
        }
        $v = (int) $q[$name];

        return $v < $min ? $min : $v;
    }
}

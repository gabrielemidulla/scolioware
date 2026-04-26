<?php

declare(strict_types=1);

namespace App\Report;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final class ReportEditMetricsService
{
    private const NUM_FIELDS = [
        'cobb_pt_deg',
        'cobb_mt_deg',
        'cobb_tl_deg',
        'cobb_thoracic_deg',
        'cobb_lumbar_deg',
        'cobb_max_deg',
    ];

    public function __construct(private readonly Connection $conn)
    {
    }

    public function isEditable(array $row): bool
    {
        $s = (string) ($row['status'] ?? '');

        return $s === 'failed' || $s === 'completed';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    public function formDefaults(array $row): array
    {
        $out = [
            'curve_type' => (string) ($row['curve_type'] ?? ''),
            'cobb_max_region' => (string) ($row['cobb_max_region'] ?? ''),
            'cobb_max_vert_superior' => (string) ($row['cobb_max_vert_superior'] ?? ''),
            'cobb_max_vert_inferior' => (string) ($row['cobb_max_vert_inferior'] ?? ''),
        ];
        foreach (self::NUM_FIELDS as $f) {
            $v = $row[$f] ?? null;
            $out[$f] = ($v !== null && $v !== '' && is_numeric($v)) ? (string) $v : '';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{ok: bool, errors: list<string>, payload?: array<string, mixed>, form?: array<string, string>}
     */
    public function validateAndBuildPayload(Request $request, array $row): array
    {
        $errors = [];
        $form = [];

        $curveDb = null;
        $curve = trim((string) $request->request->get('curve_type', ''));
        if ($curve === '') {
            $curveDb = null;
        } elseif ($curve === 'C' || $curve === 'S') {
            $curveDb = $curve;
        } else {
            $errors[] = (string) __('edit.err_curve');
        }
        $form['curve_type'] = $curve;

        $parsed = [];
        foreach (self::NUM_FIELDS as $field) {
            $s = trim((string) $request->request->get($field, ''));
            $form[$field] = $s;
            if ($s === '') {
                $parsed[$field] = null;
            } elseif (is_numeric($s)) {
                $parsed[$field] = (float) $s;
            } else {
                $errors[] = (string) __('edit.err_num', [
                    'field' => (string) __('field.' . $field),
                ]);
            }
        }

        $region = strtoupper(trim((string) $request->request->get('cobb_max_region', '')));
        $form['cobb_max_region'] = trim((string) $request->request->get('cobb_max_region', ''));
        if ($region === '') {
            $regionDb = null;
        } elseif (strlen($region) > 8) {
            $errors[] = (string) __('edit.err_region_len');
            $regionDb = null;
        } elseif (!preg_match('/^[A-Z0-9._-]+$/', $region)) {
            $errors[] = (string) __('edit.err_region_ch');
            $regionDb = null;
        } else {
            $regionDb = $region;
        }

        $vs = trim((string) $request->request->get('cobb_max_vert_superior', ''));
        $vi = trim((string) $request->request->get('cobb_max_vert_inferior', ''));
        $form['cobb_max_vert_superior'] = $vs;
        $form['cobb_max_vert_inferior'] = $vi;
        $vsDb = $vs === '' ? null : (ctype_digit($vs) ? (int) $vs : null);
        $viDb = $vi === '' ? null : (ctype_digit($vi) ? (int) $vi : null);
        if ($vs !== '' && $vsDb === null) {
            $errors[] = (string) __('edit.err_vs');
        }
        if ($vi !== '' && $viDb === null) {
            $errors[] = (string) __('edit.err_vi');
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'form' => $form];
        }

        return [
            'ok' => true,
            'errors' => [],
            'payload' => [
                'curve_type' => $curveDb,
                'cobb_pt_deg' => $parsed['cobb_pt_deg'],
                'cobb_mt_deg' => $parsed['cobb_mt_deg'],
                'cobb_tl_deg' => $parsed['cobb_tl_deg'],
                'cobb_thoracic_deg' => $parsed['cobb_thoracic_deg'],
                'cobb_lumbar_deg' => $parsed['cobb_lumbar_deg'],
                'cobb_max_region' => $regionDb,
                'cobb_max_deg' => $parsed['cobb_max_deg'],
                'cobb_max_vert_superior' => $vsDb,
                'cobb_max_vert_inferior' => $viDb,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload from validateAndBuildPayload success
     */
    public function apply(int $reportId, array $payload): void
    {
        $this->conn->update('reports', [
            'curve_type' => $payload['curve_type'],
            'cobb_pt_deg' => $payload['cobb_pt_deg'],
            'cobb_mt_deg' => $payload['cobb_mt_deg'],
            'cobb_tl_deg' => $payload['cobb_tl_deg'],
            'cobb_thoracic_deg' => $payload['cobb_thoracic_deg'],
            'cobb_lumbar_deg' => $payload['cobb_lumbar_deg'],
            'cobb_max_region' => $payload['cobb_max_region'],
            'cobb_max_deg' => $payload['cobb_max_deg'],
            'cobb_max_vert_superior' => $payload['cobb_max_vert_superior'],
            'cobb_max_vert_inferior' => $payload['cobb_max_vert_inferior'],
        ], ['id' => $reportId]);
    }
}

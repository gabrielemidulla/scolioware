<?php

declare(strict_types=1);

namespace App\Pdf;

use RuntimeException;
use Throwable;

/**
 * TCPDF-based radiology report PDF bytes (dashboard upload flow).
 */
final class ReportPdfGenerator
{
    private static function tcpdfInstallPath(): string
    {
        $env = getenv('SW_TCPDF_PATH');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }

        return '/usr/local/lib/tcpdf';
    }

    private static function requireTcpdf(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $path = self::tcpdfInstallPath() . '/tcpdf.php';
        if (!is_file($path)) {
            throw new RuntimeException(
                'TCPDF library not found at ' . $path
                . '. Rebuild the dashboard image so /usr/local/lib/tcpdf is populated.'
            );
        }
        require_once $path;
        $loaded = true;
    }

    private static function formatDegrees(mixed $value, int $decimals = 2): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '—';
        }

        return number_format((float) $value, $decimals);
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $physician
     */
    public static function render(
        array $report,
        array $physician,
        string $title,
        string $notes,
        ?string $computedImageBytes = null
    ): string {
        self::requireTcpdf();

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Scolioware');
        $pdf->SetAuthor((string) ($physician['display_name'] ?? $physician['username'] ?? 'Scolioware'));
        $pdf->SetTitle($title !== '' ? $title : ('Report #' . (int) $report['id']));
        $pdf->SetSubject('Scolioware radiology report');

        $pdf->SetMargins(15, 18, 15);
        $pdf->SetHeaderMargin(8);
        $pdf->SetFooterMargin(10);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->setHeaderFont(['helvetica', '', 9]);
        $pdf->setFooterFont(['helvetica', '', 8]);
        $pdf->SetHeaderData('', 0, 'Scolioware · Report #' . (int) $report['id'],
            'Patient: ' . trim(($report['first_name'] ?? '') . ' ' . ($report['last_name'] ?? ''))
            . ' · Tax code: ' . (string) ($report['tax_code'] ?? '—'));
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);

        $pdf->AddPage();

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, $title !== '' ? $title : ('Report #' . (int) $report['id']), 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->Ln(1);

        $h = $report['height_cm'] ?? null;
        $w = $report['weight_kg'] ?? null;
        $hw = '';
        if ($h !== null && $h !== '' && $w !== null && $w !== '' && is_numeric($h) && is_numeric($w)) {
            $hw = sprintf("\nHeight (at exam): %s cm\nWeight (at exam): %s kg", number_format((float) $h, 2), number_format((float) $w, 2));
        }
        $patientLines = sprintf(
            "Patient: %s %s\nTax code: %s\nReport ID: #%d\nCreated: %s\nCurve type: %s%s",
            (string) ($report['first_name'] ?? ''),
            (string) ($report['last_name'] ?? ''),
            (string) ($report['tax_code'] ?? '—'),
            (int) $report['id'],
            (string) ($report['created_at'] ?? '—'),
            (string) ($report['curve_type'] ?? '—'),
            $hw
        );
        $pdf->MultiCell(0, 5, $patientLines, 0, 'L');
        $pdf->Ln(2);

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 7, 'Cobb summary', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);

        $rows = [
            ['Proximal thoracic (PT)', self::formatDegrees($report['cobb_pt_deg'] ?? null) . ' °'],
            ['Main thoracic (MT)', self::formatDegrees($report['cobb_mt_deg'] ?? null) . ' °'],
            ['Thoraco-lumbar (TL)', self::formatDegrees($report['cobb_tl_deg'] ?? null) . ' °'],
            ['Thoracic (max PT/MT)', self::formatDegrees($report['cobb_thoracic_deg'] ?? null) . ' °'],
            ['Lumbar (TL)', self::formatDegrees($report['cobb_lumbar_deg'] ?? null) . ' °'],
            ['Max Cobb', strtoupper((string) ($report['cobb_max_region'] ?? '')) . ' '
                . self::formatDegrees($report['cobb_max_deg'] ?? null) . ' °'],
            ['Max Cobb vertebrae (1-based)',
                (($report['cobb_max_vert_superior'] ?? null) !== null
                    && ($report['cobb_max_vert_inferior'] ?? null) !== null)
                    ? ((int) $report['cobb_max_vert_superior'] . ' – ' . (int) $report['cobb_max_vert_inferior'])
                    : '—'],
        ];

        foreach ($rows as $i => [$label, $value]) {
            $fill = $i % 2 === 0 ? 1 : 0;
            $pdf->SetFillColor(245, 247, 248);
            $pdf->Cell(70, 6, $label, 'LTRB', 0, 'L', $fill);
            $pdf->Cell(0, 6, $value, 'LTRB', 1, 'L', $fill);
        }
        $pdf->Ln(3);

        if ($computedImageBytes !== null && $computedImageBytes !== '') {
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 7, 'Computed overlay', 0, 1, 'L');
            try {
                $pdf->Image('@' . $computedImageBytes, '', '', 90, 0, '', '', 'T', false, 300, '', false, false, 0);
            } catch (Throwable $e) {
                $pdf->SetFont('helvetica', 'I', 9);
                $pdf->MultiCell(0, 5, 'Could not embed computed image: ' . $e->getMessage(), 0, 'L');
            }
            $pdf->Ln(3);
        }

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 7, 'Physician notes', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $notesTrim = trim($notes);
        if ($notesTrim === '') {
            $notesHtml = '<p style="margin:0;color:#666;">(No notes entered.)</p>';
        } else {
            $blocks = preg_split('/\R{2,}/u', $notesTrim) ?: [$notesTrim];
            $notesHtml = '<div style="font-size:10pt;line-height:1.35;">';
            foreach ($blocks as $block) {
                $block = trim((string) $block);
                if ($block === '') {
                    continue;
                }
                $line = nl2br(htmlspecialchars($block, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                $notesHtml .= '<p style="margin:0 0 5px 0;">' . $line . '</p>';
            }
            $notesHtml .= '</div>';
        }
        $pdf->writeHTML(
            '<div style="border:1px solid #ccd3d8;padding:6px;border-radius:2px;">' . $notesHtml . '</div>',
            true,
            false,
            true,
            false,
            ''
        );

        $pdf->SetY(-15);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->Cell(0, 5,
            'Signed by ' . (string) ($physician['display_name'] ?? $physician['username'] ?? 'physician')
            . ' on ' . date('Y-m-d H:i'),
            0,
            0,
            'L'
        );

        return (string) $pdf->Output('report.pdf', 'S');
    }
}

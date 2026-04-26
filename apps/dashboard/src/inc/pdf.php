<?php

declare(strict_types=1);

require_once __DIR__ . '/inference_internal.php';

/**
 * TCPDF wrapper + helpers for Scoliosoft PDF reports.
 *
 * TCPDF is vendored into the dashboard image at /usr/local/lib/tcpdf
 * (see infra/docker/dashboard/Dockerfile). The path is exposed via the
 * SV_TCPDF_PATH env var with a sane default for local CLI use.
 */

function sv_tcpdf_path(): string
{
    $env = getenv('SV_TCPDF_PATH');
    if (is_string($env) && $env !== '') {
        return rtrim($env, '/');
    }
    return '/usr/local/lib/tcpdf';
}

function sv_require_tcpdf(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $path = sv_tcpdf_path() . '/tcpdf.php';
    if (!is_file($path)) {
        http_response_code(500);
        echo 'TCPDF library not found at ' . htmlspecialchars($path) . '.';
        echo ' Rebuild the dashboard image so /usr/local/lib/tcpdf is populated.';
        exit;
    }
    require_once $path;
    $loaded = true;
}

function sv_inference_base_url(): string
{
    $env = getenv('INFERENCE_INTERNAL_URL');
    if (is_string($env) && $env !== '') {
        return rtrim($env, '/');
    }
    return 'http://inference:8000';
}

/**
 * Format a float-ish value as "12.34" or em dash if missing.
 */
function sv_fmt_deg($value, int $decimals = 2): string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }
    return number_format((float) $value, $decimals);
}

/**
 * Fetch raw bytes from a URL (used to embed the computed image in the PDF).
 */
function sv_fetch_url_bytes(string $url, int $timeout = 15): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 400) {
        return null;
    }
    return is_string($body) ? $body : null;
}

/**
 * Render the PDF for a given report row + physician notes; return raw PDF bytes.
 *
 * @param array $report  Report row joined with patient (first/last/tax + cobb fields).
 * @param array $physician Physician row (username, display_name).
 * @param string $title  Document title.
 * @param string $notes  Free-form physician notes (plain text or simple HTML).
 * @param ?string $computedImageBytes Raw image bytes for the overlay image (optional).
 */
function sv_render_report_pdf(
    array $report,
    array $physician,
    string $title,
    string $notes,
    ?string $computedImageBytes = null
): string {
    sv_require_tcpdf();

    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Scoliosoft');
    $pdf->SetAuthor((string) ($physician['display_name'] ?? $physician['username'] ?? 'Scoliosoft'));
    $pdf->SetTitle($title !== '' ? $title : ('Report #' . (int) $report['id']));
    $pdf->SetSubject('Scoliosoft radiology report');

    $pdf->SetMargins(15, 18, 15);
    $pdf->SetHeaderMargin(8);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->setHeaderFont(['helvetica', '', 9]);
    $pdf->setFooterFont(['helvetica', '', 8]);
    $pdf->SetHeaderData('', 0, 'Scoliosoft · Report #' . (int) $report['id'],
        'Patient: ' . trim(($report['first_name'] ?? '') . ' ' . ($report['last_name'] ?? '')) .
        ' · Tax code: ' . (string) ($report['tax_code'] ?? '—'));
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
        ['Proximal thoracic (PT)', sv_fmt_deg($report['cobb_pt_deg'] ?? null) . ' °'],
        ['Main thoracic (MT)', sv_fmt_deg($report['cobb_mt_deg'] ?? null) . ' °'],
        ['Thoraco-lumbar (TL)', sv_fmt_deg($report['cobb_tl_deg'] ?? null) . ' °'],
        ['Thoracic (max PT/MT)', sv_fmt_deg($report['cobb_thoracic_deg'] ?? null) . ' °'],
        ['Lumbar (TL)', sv_fmt_deg($report['cobb_lumbar_deg'] ?? null) . ' °'],
        ['Max Cobb', strtoupper((string) ($report['cobb_max_region'] ?? '')) . ' '
            . sv_fmt_deg($report['cobb_max_deg'] ?? null) . ' °'],
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
        0, 0, 'L'
    );

    return (string) $pdf->Output('report.pdf', 'S');
}

/**
 * Upload PDF bytes + metadata to the automatic measurements backend.
 * Returns ['id' => int, 'object_key' => string, ...] on success, or null on failure (with $error set).
 */
function sv_upload_pdf_to_inference(
    int $reportId,
    int $physicianId,
    string $physicianUsername,
    string $title,
    string $notes,
    string $pdfBytes,
    string &$error
): ?array {
    $url = sv_inference_base_url() . '/v2/pdf-reports/upload';

    $tmp = tempnam(sys_get_temp_dir(), 'svpdf_');
    if ($tmp === false) {
        $error = 'Could not allocate a temp file for the PDF upload.';
        return null;
    }
    file_put_contents($tmp, $pdfBytes);

    $post = [
        'report_id' => (string) $reportId,
        'physician_id' => (string) $physicianId,
        'physician_username' => $physicianUsername,
        'title' => $title,
        'notes' => $notes,
        'pdf' => new CURLFile($tmp, 'application/pdf', 'report.pdf'),
    ];

    $headers = array_values(
        array_filter(
            sv_inference_curl_headers(),
            static fn (string $h) => $h !== 'Accept: application/json'
        )
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    @unlink($tmp);

    if ($body === false) {
        $error = 'Upload failed: ' . $curlErr;
        return null;
    }
    if ($code < 200 || $code >= 300) {
        $error = 'Upload failed (HTTP ' . $code . '): ' . substr((string) $body, 0, 500);
        return null;
    }

    $json = json_decode((string) $body, true);
    if (!is_array($json) || !isset($json['id'])) {
        $error = 'The automatic measurements service returned an invalid response.';
        return null;
    }
    return $json;
}

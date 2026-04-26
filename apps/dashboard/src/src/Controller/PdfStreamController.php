<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\PhiLogger;
use App\Infrastructure\Database;
use App\Locale\SwLocale;
use App\Storage\R2Storage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PdfStreamController extends AbstractController
{
    #[Route('/pdf.php', name: 'pdf_legacy', methods: ['GET'])]
    public function pdf(Request $request): Response
    {
        SwLocale::init();
        sw_require_auth();

        $id = (int) $request->query->get('id', 0);
        if ($id <= 0) {
            return new Response((string) __('error.missing_pdf_id'), 400, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, patient_id, report_id, pdf_object_key, deleted_at
             FROM pdf_reports WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || $row['deleted_at'] !== null) {
            return new Response((string) __('error.pdf_not_found'), 404, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        if (!R2Storage::isConfigured()) {
            return new Response((string) __('error.pdf_stream'), 503, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        $okey = trim((string) ($row['pdf_object_key'] ?? ''));
        if ($okey === '') {
            return new Response((string) __('error.pdf_not_found'), 404, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        try {
            $bytes = R2Storage::getBytes($okey);
        } catch (\Throwable $e) {
            error_log('pdf stream: ' . $e->getMessage());

            return new Response((string) __('error.pdf_stream'), 502, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        if ($bytes === null || $bytes === '') {
            return new Response((string) __('error.pdf_stream'), 502, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        PhiLogger::append('download_pdf', (int) $row['patient_id'], (int) $row['report_id'], (int) $id);

        $fname = 'scolioware-report-' . (int) $id . '.pdf';

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $fname . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Pragma' => 'no-cache',
        ]);
    }
}

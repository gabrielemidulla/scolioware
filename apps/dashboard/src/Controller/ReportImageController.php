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

final class ReportImageController extends AbstractController
{
    #[Route('/report_image.php', name: 'report_image_legacy', methods: ['GET'])]
    public function image(Request $request): Response
    {
        SwLocale::init();
        sw_require_auth();

        $rid = (int) $request->query->get('report_id', 0);
        $kind = strtolower(trim((string) $request->query->get('kind', '')));
        if ($rid <= 0 || !in_array($kind, ['original', 'computed'], true)) {
            return new Response((string) __('error.report_image_param'), 400, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $stmt = Database::pdo()->prepare('SELECT id, patient_id, status, original_object_key, computed_object_key FROM reports WHERE id = ?');
        $stmt->execute([$rid]);
        $row = $stmt->fetch();
        if (!$row) {
            return new Response('', 404);
        }

        $pid = (int) $row['patient_id'];
        PhiLogger::append('view_report_image', $pid, $rid, null);

        if ($kind === 'computed' && (string) ($row['computed_object_key'] ?? '') === '') {
            return new Response('', 404);
        }
        if ($kind === 'original' && (string) ($row['original_object_key'] ?? '') === '') {
            return new Response('', 404);
        }

        if (!R2Storage::isConfigured()) {
            return new Response((string) __('error.report_image_fetch'), 503, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        $okey = $kind === 'original'
            ? trim((string) ($row['original_object_key'] ?? ''))
            : trim((string) ($row['computed_object_key'] ?? ''));
        try {
            $bytes = R2Storage::getBytes($okey);
        } catch (\Throwable $e) {
            error_log('report_image: ' . $e->getMessage());

            return new Response((string) __('error.report_image_fetch'), 502, ['Content-Type' => 'text/html; charset=UTF-8']);
        }
        if ($bytes === null || $bytes === '') {
            return new Response((string) __('error.report_image_fetch'), 502, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        return new Response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

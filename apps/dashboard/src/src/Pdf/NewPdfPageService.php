<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Http\BackUrl;
use App\Infrastructure\Database;
use App\Storage\R2Storage;
use App\Report\ReportPageService;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final class NewPdfPageService
{
    public function __construct(private readonly ReportPageService $reportPage)
    {
    }

    public function resolveReportId(Request $request): int
    {
        $rid = (int) $request->query->get('report_id', 0);
        if ($rid <= 0 && $request->request->has('report_id')) {
            $rid = (int) $request->request->get('report_id');
        }

        return $rid;
    }

    /** @return array<string, mixed>|null */
    public function fetchReport(int $reportId): ?array
    {
        return $this->reportPage->fetchReportRow($reportId);
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $user
     *
     * @return array{
     *     redirect: bool,
     *     errors: list<string>,
     *     report_id?: int,
     *     pdf_id?: int,
     *     back: ?string,
     *     form_title: string,
     *     form_notes: string
     * }
     */
    public function processPost(Request $request, array $report, array $user): array
    {
        $errors = [];
        $title = trim((string) $request->request->get('title', ''));
        $notes = (string) $request->request->get('notes', '');
        $includeImage = $request->request->get('include_image') === '1';

        if ($title === '') {
            $title = (string) __('new_pdf.default_title', [
                'rid' => (string) (int) $report['id'],
                'surname' => (string) $report['last_name'],
                'name' => (string) $report['first_name'],
            ]);
        }

        if (mb_strlen($title) > 200) {
            $errors[] = (string) __('new_pdf.err_title_len');
        }
        if (mb_strlen($notes) > 50000) {
            $errors[] = (string) __('new_pdf.err_notes_len');
        }

        $formTitle = trim((string) $request->request->get('title', ''));
        $formNotes = (string) $request->request->get('notes', '');

        if ($errors !== []) {
            return [
                'redirect' => false,
                'errors' => $errors,
                'back' => null,
                'form_title' => $formTitle,
                'form_notes' => $formNotes,
            ];
        }

        $imageBytes = null;
        if ($includeImage && (string) ($report['computed_object_key'] ?? '') !== '') {
            if (!R2Storage::isConfigured()) {
                $errors[] = (string) __('error.report_image_fetch');
            } else {
                try {
                    $imageBytes = R2Storage::getBytes(trim((string) $report['computed_object_key']));
                } catch (Throwable) {
                    $imageBytes = null;
                }
                if ($imageBytes === null || $imageBytes === '') {
                    $errors[] = (string) __('error.report_image_fetch');
                }
            }
        }

        if ($errors !== []) {
            return [
                'redirect' => false,
                'errors' => $errors,
                'back' => null,
                'form_title' => $formTitle,
                'form_notes' => $formNotes,
            ];
        }

        try {
            $pdfBytes = ReportPdfGenerator::render($report, $user, $title, $notes, $imageBytes);
        } catch (Throwable $e) {
            return [
                'redirect' => false,
                'errors' => [(string) __('new_pdf.err_render') . $e->getMessage()],
                'back' => null,
                'form_title' => $formTitle,
                'form_notes' => $formNotes,
            ];
        }

        if ($pdfBytes === '') {
            return [
                'redirect' => false,
                'errors' => [(string) __('new_pdf.err_render')],
                'back' => null,
                'form_title' => $formTitle,
                'form_notes' => $formNotes,
            ];
        }

        $uploadErr = '';
        $pdo = Database::pdo();
        $resp = R2Storage::uploadPdfReport(
            $pdo,
            $report,
            (int) $user['id'],
            (string) $user['username'],
            $title,
            $notes,
            $pdfBytes,
            $uploadErr
        );
        if ($resp === null) {
            return [
                'redirect' => false,
                'errors' => [$uploadErr !== '' ? $uploadErr : (string) __('new_pdf.err_upload')],
                'back' => null,
                'form_title' => $formTitle,
                'form_notes' => $formNotes,
            ];
        }

        $back = null;
        $br = BackUrl::getRaw();
        if ($br !== null && BackUrl::validate($br) !== null) {
            $back = $br;
        }

        return [
            'redirect' => true,
            'errors' => [],
            'report_id' => (int) $report['id'],
            'pdf_id' => (int) $resp['id'],
            'back' => $back,
            'form_title' => '',
            'form_notes' => '',
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    public function includeImageCheckbox(Request $request, array $report): bool
    {
        if ($request->isMethod('POST')) {
            return $request->request->get('include_image') === '1';
        }

        return ($report['status'] ?? '') === 'completed'
            && trim((string) ($report['computed_object_key'] ?? '')) !== '';
    }
}

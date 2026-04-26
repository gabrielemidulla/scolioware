<?php

declare(strict_types=1);

namespace App\Report;

use App\Storage\R2Storage;
use Doctrine\DBAL\Connection;
use Throwable;

final class ReportDeletionService
{
    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @param array<string, mixed> $row report row with patient_id, original_object_key, computed_object_key
     *
     * @throws \RuntimeException on storage or DB failure (message is user-facing)
     */
    public function deleteReportWithStorage(int $reportId, array $row): void
    {
        if (!R2Storage::isConfigured()) {
            throw new \RuntimeException((string) __('report.admin_delete_failed', [
                'code' => 503,
                'detail' => 'Object storage is not configured.',
            ]));
        }

        $keys = [];
        foreach (['original_object_key', 'computed_object_key'] as $col) {
            $v = trim((string) ($row[$col] ?? ''));
            if ($v !== '') {
                $keys[] = $v;
            }
        }
        $pdfRows = $this->conn->fetchAllAssociative(
            'SELECT pdf_object_key FROM pdf_reports WHERE report_id = ?',
            [$reportId]
        );
        foreach ($pdfRows as $pr) {
            $pk = trim((string) ($pr['pdf_object_key'] ?? ''));
            if ($pk !== '') {
                $keys[] = $pk;
            }
        }
        $uniq = array_values(array_unique($keys));
        foreach ($uniq as $objectKey) {
            try {
                R2Storage::deleteObject($objectKey);
            } catch (Throwable $e) {
                $detail = mb_substr(preg_replace('/\s+/u', ' ', $e->getMessage()), 0, 500);
                throw new \RuntimeException((string) __('report.admin_delete_failed', [
                    'code' => 502,
                    'detail' => $detail,
                ]));
            }
        }

        $n = $this->conn->delete('reports', ['id' => $reportId]);
        if ($n !== 1) {
            throw new \RuntimeException((string) __('report.admin_delete_failed', [
                'code' => 404,
                'detail' => 'Report row could not be removed.',
            ]));
        }
    }
}

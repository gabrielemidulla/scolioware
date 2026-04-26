<?php

declare(strict_types=1);

namespace App\Storage;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Cloudflare R2 (S3 API) from the dashboard PHP container.
 * Inference workers use HTTP to internal_r2_blob.php instead of boto3.
 */
final class R2Storage
{
    private static function ensureVendorAutoload(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_readable($autoload)) {
            throw new RuntimeException(
                'Composer vendor/autoload.php is missing. Run `composer install` in apps/dashboard/src.'
            );
        }
        require_once $autoload;
        $done = true;
    }

    public static function isConfigured(): bool
    {
        foreach (['R2_ENDPOINT_URL', 'R2_ACCESS_KEY_ID', 'R2_SECRET_ACCESS_KEY', 'R2_BUCKET_NAME'] as $k) {
            $v = getenv($k);
            if (!is_string($v) || trim($v) === '') {
                return false;
            }
        }

        return true;
    }

    public static function isInternalKeyAllowed(string $key): bool
    {
        $k = trim($key);

        return $k !== '' && (bool) preg_match('#^patients/\d+/reports/\d+/#', $k);
    }

    /** @return S3Client */
    public static function client(): S3Client
    {
        self::ensureVendorAutoload();
        if (!self::isConfigured()) {
            throw new RuntimeException(
                'R2 is not configured. Set R2_ENDPOINT_URL, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, and R2_BUCKET_NAME.'
            );
        }
        $endpoint = rtrim((string) getenv('R2_ENDPOINT_URL'), '/');

        return new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => $endpoint,
            'credentials' => [
                'key' => (string) getenv('R2_ACCESS_KEY_ID'),
                'secret' => (string) getenv('R2_SECRET_ACCESS_KEY'),
            ],
            'use_path_style_endpoint' => true,
        ]);
    }

    public static function bucket(): string
    {
        return (string) getenv('R2_BUCKET_NAME');
    }

    public static function putBytes(string $key, string $body, string $contentType = 'application/octet-stream'): void
    {
        $client = self::client();
        $client->putObject([
            'Bucket' => self::bucket(),
            'Key' => $key,
            'Body' => $body,
            'ContentType' => $contentType,
        ]);
    }

    /** @return string|null null if object missing */
    public static function getBytes(string $key): ?string
    {
        $client = self::client();
        try {
            $result = $client->getObject([
                'Bucket' => self::bucket(),
                'Key' => $key,
            ]);
            $stream = $result['Body'] ?? null;
            if ($stream === null) {
                return null;
            }

            return (string) $stream->getContents();
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'NoSuchKey' || $e->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    public static function deleteObject(string $key): void
    {
        $k = trim($key);
        if ($k === '') {
            return;
        }
        $client = self::client();
        $client->deleteObject([
            'Bucket' => self::bucket(),
            'Key' => $k,
        ]);
    }

    public static function objectKeyOriginal(int $patientId, int $reportId): string
    {
        return "patients/{$patientId}/reports/{$reportId}/original.jpeg";
    }

    public static function objectKeyComputed(int $patientId, int $reportId): string
    {
        return "patients/{$patientId}/reports/{$reportId}/computed.jpeg";
    }

    public static function newPdfObjectKey(int $patientId, int $reportId): string
    {
        $hex = bin2hex(random_bytes(16));

        return "patients/{$patientId}/reports/{$reportId}/pdfs/{$hex}.pdf";
    }

    /**
     * @param array<string, mixed> $reportRow from reports join (needs patient_id)
     *
     * @return array<string, mixed>|null inserted row shape like inference upload JSON
     */
    public static function uploadPdfReport(
        PDO $pdo,
        array $reportRow,
        int $physicianId,
        string $physicianUsername,
        string $title,
        string $notes,
        string $pdfBytes,
        string &$error
    ): ?array {
        if (!self::isConfigured()) {
            $error = 'R2 storage is not configured.';

            return null;
        }
        if ($pdfBytes === '' || strncmp($pdfBytes, '%PDF', 4) !== 0) {
            $error = 'Uploaded file is not a valid PDF.';

            return null;
        }
        $reportId = (int) $reportRow['id'];
        $patientId = (int) $reportRow['patient_id'];
        $key = self::newPdfObjectKey($patientId, $reportId);
        try {
            self::putBytes($key, $pdfBytes, 'application/pdf');
        } catch (Throwable $e) {
            $error = 'R2 upload failed: ' . $e->getMessage();

            return null;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO pdf_reports (
          patient_id, report_id, physician_id, physician_username,
          title, notes, pdf_object_key, pdf_bytes_size
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        try {
            $stmt->execute([
                $patientId,
                $reportId,
                $physicianId,
                $physicianUsername,
                $title !== '' ? $title : null,
                $notes !== '' ? $notes : null,
                $key,
                strlen($pdfBytes),
            ]);
        } catch (Throwable $e) {
            try {
                self::deleteObject($key);
            } catch (Throwable) {
            }
            $error = 'Database error after upload: ' . $e->getMessage();

            return null;
        }
        $id = (int) $pdo->lastInsertId();

        return [
            'id' => $id,
            'report_id' => $reportId,
            'patient_id' => $patientId,
            'object_key' => $key,
            'bytes' => strlen($pdfBytes),
        ];
    }
}

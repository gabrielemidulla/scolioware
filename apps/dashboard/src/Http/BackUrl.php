<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Validated ?back= chain for same-directory scripts only (no open redirects).
 */
final class BackUrl
{
    /**
     * @return list<string>
     */
    public static function allowedScripts(): array
    {
        return [
            'index.php',
            'patients.php',
            'queue.php',
            'report.php',
            'patient.php',
            'new_report.php',
            'new_pdf.php',
            'account.php',
            'physicians.php',
            'login.php',
            'reset_password.php',
            'logout.php',
            'report_edit_metrics.php',
            'pdf.php',
            'set_language.php',
        ];
    }

    /** Current script + full query string (including any `back=` chain). */
    public static function currentLiteral(): string
    {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
        $q = http_build_query($_GET);

        return $q !== '' ? $script . '?' . $q : $script;
    }

    /** Append &back= or ?back= to a relative URL (e.g. report.php?id=3). */
    public static function appendBack(string $relativeUrl): string
    {
        $sep = str_contains($relativeUrl, '?') ? '&' : '?';

        return $relativeUrl . $sep . 'back=' . rawurlencode(self::currentLiteral());
    }

    public static function getRaw(): ?string
    {
        if (!isset($_GET['back'])) {
            return null;
        }
        $b = $_GET['back'];

        return is_string($b) ? $b : null;
    }

    /** @return non-empty-string|null Normalized script.php?… */
    public static function validate(?string $back): ?string
    {
        if ($back === null || $back === '') {
            return null;
        }
        $decoded = rawurldecode(trim($back));
        if ($decoded === '' || strlen($decoded) > 2000) {
            return null;
        }
        if (strpbrk($decoded, "\r\n\x00") !== false) {
            return null;
        }
        if (stripos($decoded, '://') !== false || str_starts_with($decoded, '//')) {
            return null;
        }
        if (str_contains($decoded, '..')) {
            return null;
        }

        $parts = explode('?', $decoded, 2);
        $pathPart = $parts[0];
        if (str_contains($pathPart, '/')) {
            return null;
        }
        $base = basename($pathPart);
        if (!in_array($base, self::allowedScripts(), true)) {
            return null;
        }
        if (isset($parts[1]) && strlen($parts[1]) > 1500) {
            return null;
        }
        if (isset($parts[1]) && preg_match('/[^\x20-\x7E]/', $parts[1])) {
            return null;
        }

        if (!isset($parts[1])) {
            return $decoded;
        }

        parse_str($parts[1], $query);
        if (!is_array($query) || count($query) > 80) {
            return null;
        }
        $clean = [];
        foreach ($query as $k => $v) {
            if (!is_string($k) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $k)) {
                continue;
            }
            if (is_array($v)) {
                return null;
            }
            $vs = (string) $v;
            if (strlen($vs) > 800) {
                continue;
            }
            $clean[$k] = $vs;
        }
        $built = http_build_query($clean, '', '&', PHP_QUERY_RFC3986);

        return $built !== '' ? $base . '?' . $built : $base;
    }

    /** Use validated ?back= or the given default relative URL. */
    public static function orDefault(string $defaultRelative): string
    {
        $v = self::validate(self::getRaw());

        return $v ?? $defaultRelative;
    }

    /**
     * Validates a same-origin relative URL for redirects (e.g. login ?next=, language ?return=).
     *
     * @param non-empty-string $defaultPath must start with /
     *
     * @return non-empty-string
     */
    public static function safeAppRedirectTarget(string $pathOrUrl, string $defaultPath): string
    {
        return RedirectValidator::safeRelativePath($pathOrUrl, $defaultPath, self::allowedScripts());
    }

    /** Re-append validated back without nesting the current page. */
    public static function preserveOn(string $relativeUrl): string
    {
        $incoming = self::validate(self::getRaw());
        if ($incoming === null) {
            return $relativeUrl;
        }
        $sep = str_contains($relativeUrl, '?') ? '&' : '?';

        return $relativeUrl . $sep . 'back=' . rawurlencode($incoming);
    }
}

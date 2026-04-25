<?php

declare(strict_types=1);

/**
 * Safe return navigation: pass the current page as ?back= (URL-encoded relative URL).
 * Only same-directory *.php targets are accepted (no open redirects).
 */

function sv_back_allowed_scripts(): array
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
    ];
}

/** Current script + full query string (including any `back=` chain). */
function sv_back_current_literal(): string
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $q = http_build_query($_GET);

    return $q !== '' ? $script . '?' . $q : $script;
}

/** Append &back= or ?back= to a relative URL (e.g. report.php?id=3). */
function sv_append_back(string $relativeUrl): string
{
    $sep = str_contains($relativeUrl, '?') ? '&' : '?';

    return $relativeUrl . $sep . 'back=' . rawurlencode(sv_back_current_literal());
}

function sv_back_get_raw(): ?string
{
    if (!isset($_GET['back'])) {
        return null;
    }
    $b = $_GET['back'];

    return is_string($b) ? $b : null;
}

/**
 * Validate a back target (raw or URL-encoded once). Returns normalized "script.php?..." or null.
 */
function sv_back_validate(?string $back): ?string
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
    if (!in_array($base, sv_back_allowed_scripts(), true)) {
        return null;
    }
    if (isset($parts[1]) && strlen($parts[1]) > 1500) {
        return null;
    }
    // Query string: allow only printable ASCII (typical GET params)
    if (isset($parts[1]) && preg_match('/[^\x20-\x7E]/', $parts[1])) {
        return null;
    }

    return $decoded;
}

/** Use validated ?back= or the given default relative URL. */
function sv_back_or(string $defaultRelative): string
{
    $v = sv_back_validate(sv_back_get_raw());

    return $v ?? $defaultRelative;
}

/**
 * Append the incoming validated `back` query to a URL (for intermediate pages like
 * report_edit_metrics → report.php without nesting the intermediate page as `back`).
 */
function sv_back_preserve_on(string $relativeUrl): string
{
    $incoming = sv_back_validate(sv_back_get_raw());
    if ($incoming === null) {
        return $relativeUrl;
    }
    $sep = str_contains($relativeUrl, '?') ? '&' : '?';

    return $relativeUrl . $sep . 'back=' . rawurlencode($incoming);
}

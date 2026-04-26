<?php

declare(strict_types=1);

if (!function_exists('__')) {
    require_once __DIR__ . '/Locale.php';
    Locale::init();
}
if (!function_exists('sv_t_gender')) {
    require_once __DIR__ . '/sv_i18n.php';
}

/**
 * Merge current GET with overrides; drop empty string / null values for cleaner URLs.
 */
function sv_query_merge(array $overrides): string
{
    $merged = array_merge($_GET, $overrides);
    foreach ($merged as $k => $v) {
        if ($v === null || $v === '') {
            unset($merged[$k]);
        }
    }
    return http_build_query($merged);
}

/**
 * Read positive int from GET, clamped to at least $min.
 */
function sv_get_int(string $name, int $default, int $min = 0): int
{
    if (!isset($_GET[$name])) {
        return $default;
    }
    $v = (int) $_GET[$name];
    return $v < $min ? $min : $v;
}

/**
 * Read optional float from GET; empty / invalid → null.
 */
function sv_get_float_opt(string $name): ?float
{
    if (!isset($_GET[$name])) {
        return null;
    }
    $s = trim((string) $_GET[$name]);
    if ($s === '') {
        return null;
    }
    if (!is_numeric($s)) {
        return null;
    }
    return (float) $s;
}

/**
 * Read optional non-empty string from GET.
 */
function sv_get_str_opt(string $name): string
{
    if (!isset($_GET[$name])) {
        return '';
    }
    return trim((string) $_GET[$name]);
}

/**
 * Escape % and _ for SQL LIKE patterns (MySQL).
 */
function sv_like_escape(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/**
 * Render Bootstrap pagination (prev / numbered / next).
 *
 * @param positive-int $page      1-based current page
 * @param positive-int $totalPages
 */
function sv_render_pagination(string $scriptPath, int $page, int $totalPages): void
{
    if ($totalPages <= 1) {
        return;
    }

    $window = 5;
    $start = max(1, $page - (int) floor($window / 2));
    $end = min($totalPages, $start + $window - 1);
    if ($end - $start + 1 < $window) {
        $start = max(1, $end - $window + 1);
    }

    echo '<nav class="sv-pagination-nav mt-2" aria-label="' . htmlspecialchars(__('pagination.aria'), ENT_QUOTES, 'UTF-8') . '"><ul class="pagination pagination-sm mb-0">';

    $prev = max(1, $page - 1);
    if ($page <= 1) {
        echo '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
    } else {
        $qs = sv_query_merge(['page' => $prev]);
        echo '<li class="page-item"><a class="page-link" href="'
            . htmlspecialchars($scriptPath . ($qs !== '' ? '?' . $qs : '')) . '">&laquo;</a></li>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $qs = sv_query_merge(['page' => $i]);
        $href = htmlspecialchars($scriptPath . ($qs !== '' ? '?' . $qs : ''));
        if ($i === $page) {
            echo '<li class="page-item active"><span class="page-link">' . (int) $i . '</span></li>';
        } else {
            echo '<li class="page-item"><a class="page-link" href="' . $href . '">' . (int) $i . '</a></li>';
        }
    }

    if ($page >= $totalPages) {
        echo '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
    } else {
        $next = min($totalPages, $page + 1);
        $qs = sv_query_merge(['page' => $next]);
        echo '<li class="page-item"><a class="page-link" href="'
            . htmlspecialchars($scriptPath . ($qs !== '' ? '?' . $qs : '')) . '">&raquo;</a></li>';
    }

    echo '</ul></nav>';
}

/**
 * Like {@see sv_render_pagination} but uses a custom query parameter (e.g. `pdf_page` on report.php).
 *
 * @param positive-int $page
 * @param positive-int $totalPages
 */
function sv_render_pagination_for(string $scriptPath, string $pageQueryKey, int $page, int $totalPages): void
{
    if ($totalPages <= 1) {
        return;
    }

    $window = 5;
    $start = max(1, $page - (int) floor($window / 2));
    $end = min($totalPages, $start + $window - 1);
    if ($end - $start + 1 < $window) {
        $start = max(1, $end - $window + 1);
    }

    echo '<nav class="sv-pagination-nav" aria-label="' . htmlspecialchars(__('pagination.aria'), ENT_QUOTES, 'UTF-8') . '"><ul class="pagination pagination-sm mb-0">';

    $prev = max(1, $page - 1);
    if ($page <= 1) {
        echo '<li class="page-item disabled"><span class="page-link">&laquo;</span></li>';
    } else {
        $qs = sv_query_merge([$pageQueryKey => $prev]);
        echo '<li class="page-item"><a class="page-link" href="'
            . htmlspecialchars($scriptPath . ($qs !== '' ? '?' . $qs : '')) . '">&laquo;</a></li>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $qs = sv_query_merge([$pageQueryKey => $i]);
        $href = htmlspecialchars($scriptPath . ($qs !== '' ? '?' . $qs : ''));
        if ($i === $page) {
            echo '<li class="page-item active"><span class="page-link">' . (int) $i . '</span></li>';
        } else {
            echo '<li class="page-item"><a class="page-link" href="' . $href . '">' . (int) $i . '</a></li>';
        }
    }

    if ($page >= $totalPages) {
        echo '<li class="page-item disabled"><span class="page-link">&raquo;</span></li>';
    } else {
        $next = min($totalPages, $page + 1);
        $qs = sv_query_merge([$pageQueryKey => $next]);
        echo '<li class="page-item"><a class="page-link" href="'
            . htmlspecialchars($scriptPath . ($qs !== '' ? '?' . $qs : '')) . '">&raquo;</a></li>';
    }

    echo '</ul></nav>';
}

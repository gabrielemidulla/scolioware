<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Same-origin relative redirect targets (login `next`, language `return`).
 */
final class RedirectValidator
{
    /**
     * @param list<string> $allowedBasenames from {@see BackUrl::allowedScripts()}
     *
     * @param non-empty-string $defaultPath must start with /
     *
     * @return non-empty-string
     */
    public static function safeRelativePath(string $pathOrUrl, string $defaultPath, array $allowedBasenames): string
    {
        $defaultPath = str_starts_with($defaultPath, '/') ? $defaultPath : '/' . $defaultPath;
        $t = trim($pathOrUrl);
        if ($t === '' || strlen($t) > 2048 || strpbrk($t, "\0") !== false) {
            return $defaultPath;
        }
        if (!str_starts_with($t, '/')) {
            $t = '/' . $t;
        }
        if (str_contains($t, '://') || str_starts_with($t, '//')) {
            return $defaultPath;
        }
        $parsed = parse_url($t);
        if (!is_array($parsed)) {
            return $defaultPath;
        }
        if (isset($parsed['scheme']) || isset($parsed['host']) || isset($parsed['port'])) {
            return $defaultPath;
        }
        $path = (string) ($parsed['path'] ?? '');
        if ($path === '' || $path[0] !== '/') {
            return $defaultPath;
        }
        $base = basename($path);
        if (!in_array($base, $allowedBasenames, true)) {
            return $defaultPath;
        }
        $query = isset($parsed['query']) ? (string) $parsed['query'] : '';
        if ($query !== '' && (strlen($query) > 1500 || preg_match('/[^\x20-\x7E]/', $query))) {
            return $defaultPath;
        }
        $out = $path . ($query !== '' ? '?' . $query : '');
        if (isset($parsed['fragment'])) {
            $frag = (string) $parsed['fragment'];
            if (strlen($frag) > 256 || preg_match('/[^\x20-\x7E]/', $frag)) {
                return $defaultPath;
            }
            $out .= '#' . $frag;
        }

        return $out;
    }
}

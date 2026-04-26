<?php

declare(strict_types=1);

use App\Locale\SwLocale;

if (!function_exists('__')) {
    /**
     * @param array<string|int, string|int|float> $params
     */
    function __(string $key, array $params = []): string
    {
        return SwLocale::t($key, $params);
    }
}

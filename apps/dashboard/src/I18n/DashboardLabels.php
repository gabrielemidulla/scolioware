<?php

declare(strict_types=1);

namespace App\I18n;

final class DashboardLabels
{
    public static function reportStatus(string $s): string
    {
        $k = 'status.' . $s;
        $t = __($k);

        return $t !== $k ? $t : $s;
    }

    public static function gender(string $g): string
    {
        $k = 'gender.' . $g;
        $t = __($k);

        return $t !== $k ? $t : $g;
    }

    public static function curveFilter(string $v): string
    {
        if ($v === 'C') {
            return __('curve.opt_c');
        }
        if ($v === 'S') {
            return __('curve.opt_s');
        }
        if ($v === 'unspecified') {
            return __('curve.opt_unspecified');
        }

        return $v;
    }
}

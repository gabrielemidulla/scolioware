<?php

declare(strict_types=1);

/** Report / queue status label (DB value → UI). */
function sv_t_report_status(string $s): string
{
    $k = 'status.' . $s;
    $t = __($k);
    return $t !== $k ? $t : $s;
}

/** Patient gender. */
function sv_t_gender(string $g): string
{
    $k = 'gender.' . $g;
    $t = __($k);
    return $t !== $k ? $t : $g;
}

/** Filter dropdown: C / S / unspecified. */
function sv_t_curve_filter(string $v): string
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

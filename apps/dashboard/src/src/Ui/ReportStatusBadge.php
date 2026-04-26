<?php

declare(strict_types=1);

namespace App\Ui;

final class ReportStatusBadge
{
    public static function cssClass(?string $status): string
    {
        return match ($status) {
            'pending' => 'sw-status sw-status-pending',
            'processing' => 'sw-status sw-status-processing',
            'completed' => 'sw-status sw-status-completed',
            'failed' => 'sw-status sw-status-failed',
            default => 'sw-status sw-status-unknown',
        };
    }

    public static function iconMarkup(?string $status): string
    {
        $icon = match ($status) {
            'pending' => 'fa-clock',
            'processing' => 'fa-gear',
            'completed' => 'fa-circle-check',
            'failed' => 'fa-triangle-exclamation',
            default => 'fa-circle-question',
        };

        return '<i class="fa-solid ' . $icon . ' sw-status-icon" aria-hidden="true"></i> ';
    }
}

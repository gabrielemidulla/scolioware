<?php

declare(strict_types=1);

namespace App\Twig;

use App\Http\BackUrl;
use App\Locale\SwLocale;
use App\I18n\DashboardLabels;
use App\Ui\ReportStatusBadge;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class LegacyTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('sw_locale_switcher_html', $this->localeSwitcherHtml(...)),
            new TwigFunction('sw_status_class', $this->statusClass(...)),
            new TwigFunction('sw_status_icon_markup', $this->statusIconMarkup(...)),
            new TwigFunction('back_append', $this->backAppend(...)),
            new TwigFunction('back_preserve', $this->backPreserve(...)),
            new TwigFunction('back_or', $this->backOr(...)),
            new TwigFunction('curve_filter_label', $this->curveFilterLabel(...)),
        ];
    }

    public function localeSwitcherHtml(): string
    {
        SwLocale::init();
        ob_start();
        SwLocale::renderLanguageSwitcher();

        return (string) ob_get_clean();
    }

    public function statusClass(?string $status): string
    {
        return ReportStatusBadge::cssClass($status);
    }

    public function statusIconMarkup(?string $status): string
    {
        return ReportStatusBadge::iconMarkup($status);
    }

    public function backAppend(string $href): string
    {
        return BackUrl::appendBack($href);
    }

    public function backPreserve(string $href): string
    {
        return BackUrl::preserveOn($href);
    }

    public function backOr(string $defaultRelative): string
    {
        return BackUrl::orDefault($defaultRelative);
    }

    public function curveFilterLabel(string $v): string
    {
        return DashboardLabels::curveFilter($v);
    }
}

<?php

declare(strict_types=1);

/**
 * One breadcrumb segment for {@see sv_topbar}.
 *
 * @param non-empty-string      $label Visible text (escaped when rendered)
 * @param non-empty-string|null $href  Link target, or null for the active (current) segment
 */
function sv_crumb(string $label, ?string $href = null): array
{
    return ['label' => $label, 'href' => $href];
}

/**
 * App chrome: breadcrumb + page title row, aligned with the sidebar brand bar.
 *
 * @param list<array{label: string, href?: string|null}> $breadcrumbs Last item should be the current page (href null/omitted)
 * @param string                                           $headingInnerHtml Content inside <h1> (icons allowed; caller must escape text)
 * @param string|null                                      $actionsHtml      Optional HTML for the right-hand button group
 */
function sv_topbar(array $breadcrumbs, string $headingInnerHtml, ?string $actionsHtml = null): void
{
    echo '<header class="sv-topbar">';
    if ($breadcrumbs !== []) {
        echo '<nav class="sv-topbar-breadcrumb" aria-label="Breadcrumb"><ol class="breadcrumb sv-breadcrumb mb-0">';
        $n = count($breadcrumbs);
        foreach ($breadcrumbs as $i => $c) {
            $label = htmlspecialchars((string) ($c['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $href = isset($c['href']) ? trim((string) $c['href']) : '';
            $isLast = $i === $n - 1;
            if ($href !== '' && !$isLast) {
                $h = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
                echo '<li class="breadcrumb-item"><a href="' . $h . '">' . $label . '</a></li>';
            } else {
                echo '<li class="breadcrumb-item active" aria-current="page">' . $label . '</li>';
            }
        }
        echo '</ol></nav>';
    }
    echo '<div class="sv-topbar-main">';
    echo '<h1 class="sv-topbar-title">' . $headingInnerHtml . '</h1>';
    if ($actionsHtml !== null && $actionsHtml !== '') {
        echo '<div class="sv-topbar-actions">' . $actionsHtml . '</div>';
    }
    echo '</div></header>';
}

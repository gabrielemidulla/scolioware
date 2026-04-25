<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/topbar.php';
require_once __DIR__ . '/Locale.php';
require_once __DIR__ . '/sv_i18n.php';
Locale::init();

/**
 * Shared layout helpers for the Scoliosoft dashboard.
 * Provides head + sidebar markup so individual pages can stay focused on content.
 */

function sv_current_page(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    return basename($script);
}

function sv_nav_items(bool $isAdmin): array
{
    $items = [
        ['href' => 'index.php',      'label' => __('nav.dashboard'),    'icon' => 'fa-gauge-high'],
        ['href' => 'patients.php',   'label' => __('nav.patients'),     'icon' => 'fa-user-injured'],
        ['href' => 'queue.php',      'label' => __('nav.report_queue'), 'icon' => 'fa-list-check'],
        ['href' => 'new_report.php', 'label' => __('nav.new_report'),   'icon' => 'fa-file-circle-plus'],
    ];
    if ($isAdmin) {
        $items[] = ['href' => 'physicians.php', 'label' => __('nav.physicians'), 'icon' => 'fa-user-doctor'];
    }
    return $items;
}

function sv_layout_start(string $pageTitle): void
{
    $current = sv_current_page();
    $user = sv_current_user();
    $isAdmin = $user !== null && (int) $user['is_admin'] === 1;
    ?><!DOCTYPE html>
<html lang="<?= htmlspecialchars(Locale::htmlLang(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> <?= htmlspecialchars(__('common.brand_suffix'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="sv-layout">
    <aside class="sv-sidebar">
        <div class="sv-brand">
            <img class="sv-brand-logo" src="assets/img/brand-scoliosoft-teal.svg" width="365" height="65" alt="Scoliosoft">
        </div>
        <ul class="sv-nav">
            <li class="sv-nav-section"><?= htmlspecialchars(__('nav.section.main'), ENT_QUOTES, 'UTF-8') ?></li>
            <?php foreach (sv_nav_items($isAdmin) as $item): ?>
                <li>
                    <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= $current === $item['href'] ? 'active' : '' ?>">
                        <i class="fa-solid <?= htmlspecialchars($item['icon']) ?> fa-fw"></i>
                        <span><?= htmlspecialchars($item['label']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
            <?php if ($user !== null): ?>
                <li class="sv-nav-section"><?= htmlspecialchars(__('nav.section.account'), ENT_QUOTES, 'UTF-8') ?></li>
                <li>
                    <a href="account.php" class="<?= $current === 'account.php' ? 'active' : '' ?>">
                        <i class="fa-solid fa-key fa-fw"></i>
                        <span><?= htmlspecialchars(__('nav.change_password'), ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                </li>
                <li>
                    <a href="logout.php">
                        <i class="fa-solid fa-arrow-right-from-bracket fa-fw"></i>
                        <span><?= htmlspecialchars(__('nav.logout'), ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
        <div class="sv-sidebar-footer">
            <?php if ($user !== null): ?>
                <?php Locale::renderLanguageSwitcher(); ?>
                <div class="sv-sidebar-user">
                    <i class="fa-solid <?= $isAdmin ? 'fa-user-shield' : 'fa-user-doctor' ?>"></i>
                    <strong><?= htmlspecialchars($user['username']) ?></strong>
                    <?= $isAdmin ? '<span class="text-warning">' . htmlspecialchars(__('nav.admin_badge'), ENT_QUOTES, 'UTF-8') . '</span>' : '' ?>
                </div>
            <?php else: ?>
                <i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars(__('footer.app_line'), ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </div>
    </aside>
    <main class="sv-content">
    <?php
}

function sv_layout_end(): void
{
    ?>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
    <?php
}

function sv_status_class(?string $status): string
{
    return match ($status) {
        'pending' => 'sv-status sv-status-pending',
        'processing' => 'sv-status sv-status-processing',
        'completed' => 'sv-status sv-status-completed',
        'failed' => 'sv-status sv-status-failed',
        default => 'sv-status sv-status-unknown',
    };
}

function sv_status_icon_markup(?string $status): string
{
    $icon = match ($status) {
        'pending' => 'fa-clock',
        'processing' => 'fa-gear',
        'completed' => 'fa-circle-check',
        'failed' => 'fa-triangle-exclamation',
        default => 'fa-circle-question',
    };

    return '<i class="fa-solid ' . $icon . ' sv-status-icon" aria-hidden="true"></i> ';
}

<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';

sv_require_auth();

try {
    db()->query('SELECT 1');
    $dbOk = true;
} catch (Throwable $e) {
    $dbOk = false;
    $dbErr = $e->getMessage();
}

sv_layout_start(__('index.title'));
ob_start(); ?>
    <a href="new_report.php" class="btn btn-primary">
        <i class="fa-solid fa-file-circle-plus fa-fw"></i> <?= htmlspecialchars(__('index.new_report_btn'), ENT_QUOTES, 'UTF-8') ?>
    </a>
<?php
$topActions = ob_get_clean();
sv_topbar(
    [],
    '<i class="fa-solid fa-gauge-high"></i> ' . htmlspecialchars(__('index.title'), ENT_QUOTES, 'UTF-8'),
    $topActions
);
?>

<div class="row g-3">
    <div class="col-md-6">
        <div class="sv-card">
            <div class="sv-card-header">
                <i class="fa-solid fa-database"></i> <?= htmlspecialchars(__('index.db_card'), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="sv-card-body">
                <?php if ($dbOk): ?>
                    <p class="mb-0">
                        <span class="sv-status sv-status-completed">
                            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars(__('index.db_ok'), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </p>
                <?php else: ?>
                    <p class="mb-2">
                        <span class="sv-status sv-status-failed">
                            <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars(__('index.db_error'), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </p>
                    <pre class="mb-0"><?= htmlspecialchars($dbErr ?? '') ?></pre>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php sv_layout_end(); ?>

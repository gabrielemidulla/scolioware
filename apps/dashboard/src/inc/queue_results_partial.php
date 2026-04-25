<?php

declare(strict_types=1);

/**
 * Report queue results card (table + pagination).
 *
 * @var array<int, array<string, mixed>> $rows
 */
$nd = __('common.dash');
$countSub = (int) $totalReports
    . ' '
    . ((int) $totalReports === 1 ? __('queue_table.match_s') : __('queue_table.match_p'));
if ((int) $totalReports > 0) {
    $countSub .= ' · ' . __('queue_table.rows') . ' ' . (int) $from . '–' . (int) $to;
}
?>
<div class="sv-card">
    <div class="sv-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span>
            <i class="fa-solid fa-table"></i> <?= htmlspecialchars(__('queue_table.results'), ENT_QUOTES, 'UTF-8') ?>
            <span class="text-muted fw-normal">
                (<?= htmlspecialchars($countSub, ENT_QUOTES, 'UTF-8') ?>)
            </span>
        </span>
        <span class="text-muted small"><?= htmlspecialchars(__('queue_table.per_page'), ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="sv-card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars(__('queue_table.col_id'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('queue_table.col_patient'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('queue_table.col_tax'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('queue_table.col_status'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('queue_table.col_curve'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('queue_table.col_tor'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('queue_table.col_lum'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('queue_table.col_max'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('queue_table.col_height'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('queue_table.col_weight'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('queue_table.col_created'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(__('queue_table.col_error'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>#<?= (int) $r['id'] ?></td>
                        <td>
                            <a href="<?= htmlspecialchars(sv_append_back('patient.php?id=' . (int) $r['patient_id']), ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?>
                            </a>
                        </td>
                        <td><code><?= htmlspecialchars($r['tax_code']) ?></code></td>
                        <td>
                            <span class="<?= sv_status_class($r['status']) ?>">
                                <?= sv_status_icon_markup($r['status']) ?>
                                <span class="sv-status-text"><?= htmlspecialchars(sv_t_report_status((string) $r['status'])) ?></span>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($r['curve_type'] ?? '') ?></td>
                        <td><?php
                            $v = $r['cobb_thoracic_deg'] ?? null;
                            echo $v !== null && $v !== '' ? htmlspecialchars(number_format((float) $v, 2)) : $nd;
                        ?></td>
                        <td><?php
                            $v = $r['cobb_lumbar_deg'] ?? null;
                            echo $v !== null && $v !== '' ? htmlspecialchars(number_format((float) $v, 2)) : $nd;
                        ?></td>
                        <td><?php
                            $reg = $r['cobb_max_region'] ?? '';
                            $mx = $r['cobb_max_deg'] ?? null;
                            if ($mx !== null && $mx !== '') {
                                echo htmlspecialchars(strtoupper((string) $reg) . ' ' . number_format((float) $mx, 2) . '°');
                            } else {
                                echo $nd;
                            }
                        ?></td>
                        <td><?php
                            $hc = $r['height_cm'] ?? null;
                            echo $hc !== null && $hc !== '' ? htmlspecialchars(number_format((float) $hc, 2)) . ' cm' : $nd;
                        ?></td>
                        <td><?php
                            $wk = $r['weight_kg'] ?? null;
                            echo $wk !== null && $wk !== '' ? htmlspecialchars(number_format((float) $wk, 2)) . ' kg' : $nd;
                        ?></td>
                        <td><span class="text-muted"><?= htmlspecialchars($r['created_at']) ?></span></td>
                        <td><?php
                            $err = (string) ($r['error_message'] ?? '');
                            echo htmlspecialchars(strlen($err) > 80 ? substr($err, 0, 77) . '...' : $err);
                        ?></td>
                        <td>
                            <a href="<?= htmlspecialchars(sv_append_back('report.php?id=' . (int) $r['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-sm">
                                <i class="fa-solid fa-eye fa-fw"></i> <?= htmlspecialchars(__('queue_table.view'), ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="13" class="text-center text-muted py-4"><?= htmlspecialchars(__('queue_table.empty'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="p-2 border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span class="text-muted small"><?= htmlspecialchars(__('patients.page', ['cur' => (string) (int) $page, 'max' => (string) (int) $totalPages]), ENT_QUOTES, 'UTF-8') ?></span>
            <?php sv_render_pagination('queue.php', $page, $totalPages); ?>
        </div>
    </div>
</div>

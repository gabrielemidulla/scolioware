<?php

declare(strict_types=1);

/**
 * Report queue results card (table + pagination).
 * Used by queue.php full page and queue fragment responses.
 *
 * @var array<int, array<string, mixed>> $rows
 */
?>
<div class="sv-card">
    <div class="sv-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span>
            <i class="fa-solid fa-table"></i> Results
            <span class="text-muted fw-normal">
                (<?= (int) $totalReports ?> match<?= $totalReports > 0 ? ' · rows ' . (int) $from . '–' . (int) $to : '' ?>)
            </span>
        </span>
        <span class="text-muted small">20 per page</span>
    </div>
    <div class="sv-card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Patient</th>
                        <th>Tax code</th>
                        <th>Status</th>
                        <th>Curve</th>
                        <th>Thoracic °</th>
                        <th>Lumbar °</th>
                        <th>Max Cobb</th>
                        <th>Height</th>
                        <th>Weight</th>
                        <th>Created</th>
                        <th>Error</th>
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
                                <span class="sv-status-text"><?= htmlspecialchars($r['status']) ?></span>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($r['curve_type'] ?? '') ?></td>
                        <td><?php
                            $v = $r['cobb_thoracic_deg'] ?? null;
                            echo $v !== null && $v !== '' ? htmlspecialchars(number_format((float) $v, 2)) : '—';
                        ?></td>
                        <td><?php
                            $v = $r['cobb_lumbar_deg'] ?? null;
                            echo $v !== null && $v !== '' ? htmlspecialchars(number_format((float) $v, 2)) : '—';
                        ?></td>
                        <td><?php
                            $reg = $r['cobb_max_region'] ?? '';
                            $mx = $r['cobb_max_deg'] ?? null;
                            if ($mx !== null && $mx !== '') {
                                echo htmlspecialchars(strtoupper((string) $reg) . ' ' . number_format((float) $mx, 2) . '°');
                            } else {
                                echo '—';
                            }
                        ?></td>
                        <td><?php
                            $hc = $r['height_cm'] ?? null;
                            echo $hc !== null && $hc !== '' ? htmlspecialchars(number_format((float) $hc, 2)) . ' cm' : '—';
                        ?></td>
                        <td><?php
                            $wk = $r['weight_kg'] ?? null;
                            echo $wk !== null && $wk !== '' ? htmlspecialchars(number_format((float) $wk, 2)) . ' kg' : '—';
                        ?></td>
                        <td><span class="text-muted"><?= htmlspecialchars($r['created_at']) ?></span></td>
                        <td><?php
                            $err = (string) ($r['error_message'] ?? '');
                            echo htmlspecialchars(strlen($err) > 80 ? substr($err, 0, 77) . '...' : $err);
                        ?></td>
                        <td>
                            <a href="<?= htmlspecialchars(sv_append_back('report.php?id=' . (int) $r['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-sm">
                                <i class="fa-solid fa-eye fa-fw"></i> View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="13" class="text-center text-muted py-4">No reports match these filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="p-2 border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span class="text-muted small">Page <?= (int) $page ?> of <?= (int) $totalPages ?></span>
            <?php sv_render_pagination('queue.php', $page, $totalPages); ?>
        </div>
    </div>
</div>

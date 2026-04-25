<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/pagination.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/back.php';

sv_require_auth();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_patient'])) {
    $stmt = $pdo->prepare(
        'INSERT INTO patients (first_name, last_name, tax_code, birth_date, gender) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        trim($_POST['first_name'] ?? ''),
        trim($_POST['last_name'] ?? ''),
        strtoupper(trim($_POST['tax_code'] ?? '')),
        $_POST['birth_date'] ?? '',
        $_POST['gender'] ?? 'male',
    ]);
    header('Location: patients.php');
    exit;
}

$pHtMin = sv_get_float_opt('p_ht_min');
$pHtMax = sv_get_float_opt('p_ht_max');
if ($pHtMin !== null && $pHtMax !== null && $pHtMin > $pHtMax) {
    [$pHtMin, $pHtMax] = [$pHtMax, $pHtMin];
}
$pWtMin = sv_get_float_opt('p_wt_min');
$pWtMax = sv_get_float_opt('p_wt_max');
if ($pWtMin !== null && $pWtMax !== null && $pWtMin > $pWtMax) {
    [$pWtMin, $pWtMax] = [$pWtMax, $pWtMin];
}

$whereP = ['1=1'];
$paramsP = [];
if ($pHtMin !== null) {
    $whereP[] = 'last_height_cm IS NOT NULL AND last_height_cm >= ?';
    $paramsP[] = $pHtMin;
}
if ($pHtMax !== null) {
    $whereP[] = 'last_height_cm IS NOT NULL AND last_height_cm <= ?';
    $paramsP[] = $pHtMax;
}
if ($pWtMin !== null) {
    $whereP[] = 'last_weight_kg IS NOT NULL AND last_weight_kg >= ?';
    $paramsP[] = $pWtMin;
}
if ($pWtMax !== null) {
    $whereP[] = 'last_weight_kg IS NOT NULL AND last_weight_kg <= ?';
    $paramsP[] = $pWtMax;
}
$whereSqlP = implode(' AND ', $whereP);

$perPage = 10;
$page = sv_get_int('page', 1, 1);
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE {$whereSqlP}");
$countStmt->execute($paramsP);
$totalPatients = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalPatients / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$limitInt = (int) $perPage;
$offsetInt = (int) $offset;

$listStmt = $pdo->prepare(
    "SELECT * FROM patients WHERE {$whereSqlP} ORDER BY id DESC LIMIT {$limitInt} OFFSET {$offsetInt}"
);
$listStmt->execute($paramsP);
$rows = $listStmt->fetchAll();

$from = $totalPatients === 0 ? 0 : $offset + 1;
$to = min($offset + count($rows), $totalPatients);
$nd = __('common.dash');

sv_layout_start(__('patients.title'));
sv_topbar(
    [
        sv_crumb(__('crumb.dashboard'), 'index.php'),
        sv_crumb(__('patients.title'), null),
    ],
    '<i class="fa-solid fa-user-injured"></i> ' . __('patients.title'),
    null
);
?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="sv-card">
            <div class="sv-card-header">
                <i class="fa-solid fa-user-plus"></i> <?= htmlspecialchars(__('patients.create'), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="sv-card-body">
                <form method="post">
                    <input type="hidden" name="create_patient" value="1">
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars(__('patients.label_first'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" name="first_name" required maxlength="191">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars(__('patients.label_surname'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" name="last_name" required maxlength="191">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars(__('patients.label_tax'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" name="tax_code" required maxlength="32" style="text-transform: uppercase;">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars(__('patients.label_birth'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" type="date" name="birth_date" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= htmlspecialchars(__('patients.label_gender'), ENT_QUOTES, 'UTF-8') ?></label>
                        <select class="form-select" name="gender">
                            <option value="male"><?= htmlspecialchars(__('patients.gender_m'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="female"><?= htmlspecialchars(__('patients.gender_f'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="non_binary"><?= htmlspecialchars(__('patients.gender_nb'), ENT_QUOTES, 'UTF-8') ?></option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk fa-fw"></i> <?= htmlspecialchars(__('patients.save'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="sv-card sv-filters mb-3">
            <div class="sv-card-header">
                <i class="fa-solid fa-filter"></i> <?= htmlspecialchars(__('patients.filter_vitals'), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="sv-card-body">
                <form method="get" action="patients.php" class="row g-2">
                    <input type="hidden" name="page" value="1">
                    <?php
                    $backRaw = sv_back_get_raw();
                    if ($backRaw !== null && sv_back_validate($backRaw) !== null): ?>
                        <input type="hidden" name="back" value="<?= htmlspecialchars($backRaw, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endif; ?>
                    <div class="col-6 col-md-3">
                        <label class="form-label"><?= htmlspecialchars(__('patients.ht_min'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" name="p_ht_min" value="<?= $pHtMin !== null ? htmlspecialchars((string) $pHtMin) : '' ?>" inputmode="decimal">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label"><?= htmlspecialchars(__('patients.ht_max'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" name="p_ht_max" value="<?= $pHtMax !== null ? htmlspecialchars((string) $pHtMax) : '' ?>" inputmode="decimal">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label"><?= htmlspecialchars(__('patients.wt_min'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" name="p_wt_min" value="<?= $pWtMin !== null ? htmlspecialchars((string) $pWtMin) : '' ?>" inputmode="decimal">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label"><?= htmlspecialchars(__('patients.wt_max'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input class="form-control" name="p_wt_max" value="<?= $pWtMax !== null ? htmlspecialchars((string) $pWtMax) : '' ?>" inputmode="decimal">
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-2 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-magnifying-glass fa-fw"></i> <?= htmlspecialchars(__('patients.apply'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <a href="<?= htmlspecialchars(sv_back_preserve_on('patients.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-sm"><?= htmlspecialchars(__('patients.clear'), ENT_QUOTES, 'UTF-8') ?></a>
                    </div>
                </form>
            </div>
        </div>
        <div class="sv-card">
            <div class="sv-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                <span>
                    <i class="fa-solid fa-list"></i> <?= htmlspecialchars(__('patients.list'), ENT_QUOTES, 'UTF-8') ?>
                    <span class="text-muted fw-normal">
                        (<?= (int) $totalPatients ?> <?= htmlspecialchars(__('patients.total'), ENT_QUOTES, 'UTF-8') ?><?= $totalPatients > 0 ? ' · ' . (int) $from . '–' . (int) $to : '' ?>)
                    </span>
                </span>
                <span class="text-muted small"><?= htmlspecialchars(__('patients.per_page', ['n' => '10']), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="sv-card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th><?= htmlspecialchars(__('patients.col_id'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('patients.col_name'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('patients.col_surname'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('patients.col_tax'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('patients.col_birth'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('patients.col_gender'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('patients.col_h'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th><?= htmlspecialchars(__('patients.col_w'), ENT_QUOTES, 'UTF-8') ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?= (int) $r['id'] ?></td>
                                <td><?= htmlspecialchars($r['first_name']) ?></td>
                                <td><?= htmlspecialchars($r['last_name']) ?></td>
                                <td><code><?= htmlspecialchars($r['tax_code']) ?></code></td>
                                <td><?= htmlspecialchars($r['birth_date']) ?></td>
                                <td><?php
                                    $g = (string) $r['gender'];
                                    echo htmlspecialchars(sv_t_gender($g));
                                ?></td>
                                <td><?php
                                    $lh = $r['last_height_cm'] ?? null;
                                    echo $lh !== null && $lh !== '' ? htmlspecialchars(number_format((float) $lh, 2)) : $nd;
                                ?></td>
                                <td><?php
                                    $lw = $r['last_weight_kg'] ?? null;
                                    echo $lw !== null && $lw !== '' ? htmlspecialchars(number_format((float) $lw, 2)) : $nd;
                                ?></td>
                                <td class="text-end">
                                    <a href="<?= htmlspecialchars(sv_append_back('patient.php?id=' . (int) $r['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-sm">
                                        <i class="fa-solid fa-file-medical fa-fw"></i> <?= htmlspecialchars(__('patients.reports'), ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4"><?php
                                $noF = $pHtMin === null && $pHtMax === null && $pWtMin === null && $pWtMax === null;
                                echo htmlspecialchars(
                                    $totalPatients === 0 && $noF ? __('patients.no_data') : __('patients.no_filter'),
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                            ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="p-2 border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <span class="text-muted small"><?= htmlspecialchars(__('patients.page', ['cur' => (string) (int) $page, 'max' => (string) (int) $totalPages]), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php sv_render_pagination('patients.php', $page, $totalPages); ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php sv_layout_end(); ?>

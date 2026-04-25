<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/pagination.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/back.php';

sv_require_auth();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Missing patient id';
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM patients WHERE id = ?');
$stmt->execute([$id]);
$patient = $stmt->fetch();
if (!$patient) {
    http_response_code(404);
    echo 'Patient not found';
    exit;
}

$allowedStatus = ['pending', 'processing', 'completed', 'failed'];

$status = sv_get_str_opt('status');
if ($status !== '' && !in_array($status, $allowedStatus, true)) {
    $status = '';
}
$allowedCurve = ['', 'C', 'S', 'unspecified'];
$curve = sv_get_str_opt('curve');
if (!in_array($curve, $allowedCurve, true)) {
    $curve = '';
}

$torMin = sv_get_float_opt('tor_min');
$torMax = sv_get_float_opt('tor_max');
if ($torMin !== null && $torMax !== null && $torMin > $torMax) {
    [$torMin, $torMax] = [$torMax, $torMin];
}

$lumMin = sv_get_float_opt('lum_min');
$lumMax = sv_get_float_opt('lum_max');
if ($lumMin !== null && $lumMax !== null && $lumMin > $lumMax) {
    [$lumMin, $lumMax] = [$lumMax, $lumMin];
}

$cobbMin = sv_get_float_opt('cobb_min');
$cobbMax = sv_get_float_opt('cobb_max');
if ($cobbMin !== null && $cobbMax !== null && $cobbMin > $cobbMax) {
    [$cobbMin, $cobbMax] = [$cobbMax, $cobbMin];
}

$hCmMin = sv_get_float_opt('h_cm_min');
$hCmMax = sv_get_float_opt('h_cm_max');
if ($hCmMin !== null && $hCmMax !== null && $hCmMin > $hCmMax) {
    [$hCmMin, $hCmMax] = [$hCmMax, $hCmMin];
}

$wKgMin = sv_get_float_opt('w_kg_min');
$wKgMax = sv_get_float_opt('w_kg_max');
if ($wKgMin !== null && $wKgMax !== null && $wKgMin > $wKgMax) {
    [$wKgMin, $wKgMax] = [$wKgMax, $wKgMin];
}

$createdFrom = sv_get_str_opt('created_from');
$createdTo = sv_get_str_opt('created_to');
if ($createdFrom !== '' && $createdTo !== '' && $createdFrom > $createdTo) {
    [$createdFrom, $createdTo] = [$createdTo, $createdFrom];
}
if ($createdFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdFrom)) {
    $createdFrom = '';
}
if ($createdTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdTo)) {
    $createdTo = '';
}

$ridExact = isset($_GET['rid']) ? trim((string) $_GET['rid']) : '';
$ridExactInt = $ridExact !== '' && ctype_digit($ridExact) ? (int) $ridExact : 0;

$ridMin = sv_get_int('rid_min', 0, 0);
$ridMax = sv_get_int('rid_max', 0, 0);
if ($ridExactInt <= 0 && $ridMin > 0 && $ridMax > 0 && $ridMin > $ridMax) {
    [$ridMin, $ridMax] = [$ridMax, $ridMin];
}

$perPage = 20;
$page = sv_get_int('page', 1, 1);

$where = ['r.patient_id = ?'];
$params = [$id];

if ($ridExactInt > 0) {
    $where[] = 'r.id = ?';
    $params[] = $ridExactInt;
} else {
    if ($ridMin > 0) {
        $where[] = 'r.id >= ?';
        $params[] = $ridMin;
    }
    if ($ridMax > 0) {
        $where[] = 'r.id <= ?';
        $params[] = $ridMax;
    }
}

if ($status !== '') {
    $where[] = 'r.status = ?';
    $params[] = $status;
}

if ($curve === 'C' || $curve === 'S') {
    $where[] = 'r.curve_type = ?';
    $params[] = $curve;
} elseif ($curve === 'unspecified') {
    $where[] = 'r.curve_type IS NULL';
}

if ($torMin !== null) {
    $where[] = '(r.cobb_thoracic_deg IS NOT NULL AND r.cobb_thoracic_deg >= ?)';
    $params[] = $torMin;
}
if ($torMax !== null) {
    $where[] = '(r.cobb_thoracic_deg IS NOT NULL AND r.cobb_thoracic_deg <= ?)';
    $params[] = $torMax;
}

if ($lumMin !== null) {
    $where[] = '(r.cobb_lumbar_deg IS NOT NULL AND r.cobb_lumbar_deg >= ?)';
    $params[] = $lumMin;
}
if ($lumMax !== null) {
    $where[] = '(r.cobb_lumbar_deg IS NOT NULL AND r.cobb_lumbar_deg <= ?)';
    $params[] = $lumMax;
}

if ($cobbMin !== null) {
    $where[] = '(r.cobb_max_deg IS NOT NULL AND r.cobb_max_deg >= ?)';
    $params[] = $cobbMin;
}
if ($cobbMax !== null) {
    $where[] = '(r.cobb_max_deg IS NOT NULL AND r.cobb_max_deg <= ?)';
    $params[] = $cobbMax;
}

if ($hCmMin !== null) {
    $where[] = '(r.height_cm IS NOT NULL AND r.height_cm >= ?)';
    $params[] = $hCmMin;
}
if ($hCmMax !== null) {
    $where[] = '(r.height_cm IS NOT NULL AND r.height_cm <= ?)';
    $params[] = $hCmMax;
}
if ($wKgMin !== null) {
    $where[] = '(r.weight_kg IS NOT NULL AND r.weight_kg >= ?)';
    $params[] = $wKgMin;
}
if ($wKgMax !== null) {
    $where[] = '(r.weight_kg IS NOT NULL AND r.weight_kg <= ?)';
    $params[] = $wKgMax;
}

if ($createdFrom !== '') {
    $where[] = 'DATE(r.created_at) >= ?';
    $params[] = $createdFrom;
}
if ($createdTo !== '') {
    $where[] = 'DATE(r.created_at) <= ?';
    $params[] = $createdTo;
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE {$whereSql}");
$countStmt->execute($params);
$totalReports = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalReports / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$limitInt = (int) $perPage;
$offsetInt = (int) $offset;

$listSql = "SELECT r.id, r.status, r.curve_type, r.created_at,
               r.height_cm, r.weight_kg,
               r.cobb_thoracic_deg, r.cobb_lumbar_deg, r.cobb_max_deg, r.cobb_max_region,
               r.error_message
        FROM reports r
        WHERE {$whereSql}
        ORDER BY r.created_at DESC, r.id DESC
        LIMIT {$limitInt} OFFSET {$offsetInt}";

$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$reports = $listStmt->fetchAll();

$from = $totalReports === 0 ? 0 : $offset + 1;
$to = min($offset + count($reports), $totalReports);

$genderLabel = $patient['gender'] === 'non_binary' ? 'non-binary' : (string) $patient['gender'];

sv_layout_start('Patient · ' . $patient['last_name'] . ', ' . $patient['first_name']);
$patientCrumbLabel = $patient['last_name'] . ', ' . $patient['first_name'];
ob_start();
if (sv_back_validate(sv_back_get_raw()) !== null): ?>
            <a href="<?= htmlspecialchars(sv_back_or('patients.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left fa-fw"></i> Back
            </a>
        <?php else: ?>
            <a href="patients.php" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left fa-fw"></i> All patients
            </a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars(sv_append_back('new_report.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">
            <i class="fa-solid fa-file-circle-plus fa-fw"></i> New report
        </a>
<?php
$topActions = ob_get_clean();
sv_topbar(
    [
        sv_crumb('Dashboard', 'index.php'),
        sv_crumb('Patients', 'patients.php'),
        sv_crumb($patientCrumbLabel, null),
    ],
    '<i class="fa-solid fa-user-injured"></i> ' . htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']),
    $topActions
);
?>

<div class="sv-card mb-3">
    <div class="sv-card-header">
        <i class="fa-solid fa-id-card"></i> Patient details
    </div>
    <div class="sv-card-body">
        <div class="row g-2 small">
            <div class="col-md-3"><span class="text-muted">Patient ID</span><br><strong>#<?= (int) $patient['id'] ?></strong></div>
            <div class="col-md-3"><span class="text-muted">Tax code</span><br><code><?= htmlspecialchars($patient['tax_code']) ?></code></div>
            <div class="col-md-3"><span class="text-muted">Birth date</span><br><?= htmlspecialchars($patient['birth_date']) ?></div>
            <div class="col-md-3"><span class="text-muted">Gender</span><br><?= htmlspecialchars($genderLabel) ?></div>
            <div class="col-md-3"><span class="text-muted">Last height (cm)</span><br><?php
                $lh = $patient['last_height_cm'] ?? null;
                echo $lh !== null && $lh !== '' && is_numeric($lh) ? '<strong>' . htmlspecialchars(number_format((float) $lh, 2)) . '</strong>' : '<span class="text-muted">—</span>';
            ?></div>
            <div class="col-md-3"><span class="text-muted">Last weight (kg)</span><br><?php
                $lw = $patient['last_weight_kg'] ?? null;
                echo $lw !== null && $lw !== '' && is_numeric($lw) ? '<strong>' . htmlspecialchars(number_format((float) $lw, 2)) . '</strong>' : '<span class="text-muted">—</span>';
            ?></div>
        </div>
    </div>
</div>

<div class="sv-card sv-filters mb-3">
    <div class="sv-card-header">
        <i class="fa-solid fa-filter"></i> Report filters
    </div>
    <div class="sv-card-body">
        <form method="get" action="patient.php" class="row g-2">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <input type="hidden" name="page" value="1">
            <?php
            $backRaw = sv_back_get_raw();
            if ($backRaw !== null && sv_back_validate($backRaw) !== null): ?>
                <input type="hidden" name="back" value="<?= htmlspecialchars($backRaw, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>

            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All</option>
                    <?php foreach ($allowedStatus as $st): ?>
                        <option value="<?= htmlspecialchars($st) ?>"<?= $status === $st ? ' selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label">Curve type</label>
                <select class="form-select" name="curve">
                    <option value=""<?= $curve === '' ? ' selected' : '' ?>>All</option>
                    <option value="C"<?= $curve === 'C' ? ' selected' : '' ?>>C (single)</option>
                    <option value="S"<?= $curve === 'S' ? ' selected' : '' ?>>S (double)</option>
                    <option value="unspecified"<?= $curve === 'unspecified' ? ' selected' : '' ?>>Not classified</option>
                </select>
            </div>

            <div class="col-6 col-md-3 col-lg-1">
                <label class="form-label">Report ID</label>
                <input class="form-control" name="rid" value="<?= htmlspecialchars($ridExact) ?>" placeholder="exact" inputmode="numeric">
            </div>
            <div class="col-6 col-md-3 col-lg-1">
                <label class="form-label">ID min</label>
                <input class="form-control" name="rid_min" value="<?= $ridMin > 0 ? (int) $ridMin : '' ?>" placeholder="≥" inputmode="numeric">
            </div>
            <div class="col-6 col-md-3 col-lg-1">
                <label class="form-label">ID max</label>
                <input class="form-control" name="rid_max" value="<?= $ridMax > 0 ? (int) $ridMax : '' ?>" placeholder="≤" inputmode="numeric">
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label">Thoracic ° min</label>
                <input class="form-control" name="tor_min" value="<?= $torMin !== null ? htmlspecialchars((string) $torMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label">Thoracic ° max</label>
                <input class="form-control" name="tor_max" value="<?= $torMax !== null ? htmlspecialchars((string) $torMax) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label">Lumbar ° min</label>
                <input class="form-control" name="lum_min" value="<?= $lumMin !== null ? htmlspecialchars((string) $lumMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label">Lumbar ° max</label>
                <input class="form-control" name="lum_max" value="<?= $lumMax !== null ? htmlspecialchars((string) $lumMax) : '' ?>" inputmode="decimal">
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label">Max Cobb ° min</label>
                <input class="form-control" name="cobb_min" value="<?= $cobbMin !== null ? htmlspecialchars((string) $cobbMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label">Max Cobb ° max</label>
                <input class="form-control" name="cobb_max" value="<?= $cobbMax !== null ? htmlspecialchars((string) $cobbMax) : '' ?>" inputmode="decimal">
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label">Height (cm) min</label>
                <input class="form-control" name="h_cm_min" value="<?= $hCmMin !== null ? htmlspecialchars((string) $hCmMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label">Height (cm) max</label>
                <input class="form-control" name="h_cm_max" value="<?= $hCmMax !== null ? htmlspecialchars((string) $hCmMax) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label">Weight (kg) min</label>
                <input class="form-control" name="w_kg_min" value="<?= $wKgMin !== null ? htmlspecialchars((string) $wKgMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label">Weight (kg) max</label>
                <input class="form-control" name="w_kg_max" value="<?= $wKgMax !== null ? htmlspecialchars((string) $wKgMax) : '' ?>" inputmode="decimal">
            </div>

            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label">Created from</label>
                <input class="form-control" type="date" name="created_from" value="<?= htmlspecialchars($createdFrom) ?>">
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label">Created to</label>
                <input class="form-control" type="date" name="created_to" value="<?= htmlspecialchars($createdTo) ?>">
            </div>

            <div class="col-12 d-flex flex-wrap gap-2 align-items-end mt-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-magnifying-glass fa-fw"></i> Apply filters
                </button>
                <a href="<?= htmlspecialchars(sv_back_preserve_on('patient.php?id=' . (int) $id), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                    <i class="fa-solid fa-xmark fa-fw"></i> Clear
                </a>
            </div>
        </form>
        <p class="text-muted small mb-0 mt-2">
            <strong>Δ vs prior</strong> compares each report to the next-older exam (same patient).
        </p>
    </div>
</div>

<div class="sv-card">
    <div class="sv-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span>
            <i class="fa-solid fa-file-medical"></i> Reports
            <span class="text-muted fw-normal">
                (<?= (int) $totalReports ?> match<?= $totalReports > 0 ? ' · rows ' . (int) $from . '–' . (int) $to : '' ?> · newest first)
            </span>
        </span>
        <span class="text-muted small">20 per page</span>
    </div>
    <div class="sv-card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Report</th>
                        <th>Created</th>
                        <th>Status</th>
                        <th>Curve</th>
                        <th>Thoracic °</th>
                        <th>Lumbar °</th>
                        <th>Max Cobb</th>
                        <th>Height</th>
                        <th>Weight</th>
                        <th>Δ H vs prior</th>
                        <th>Δ W vs prior</th>
                        <th>Error</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reports as $ri => $r): ?>
                    <?php
                    $older = $reports[$ri + 1] ?? null;
                    $dh = '—';
                    $dw = '—';
                    if ($older !== null) {
                        $ch = $r['height_cm'] ?? null;
                        $cw = $r['weight_kg'] ?? null;
                        $oh = $older['height_cm'] ?? null;
                        $ow = $older['weight_kg'] ?? null;
                        if ($ch !== null && $ch !== '' && is_numeric($ch) && $oh !== null && $oh !== '' && is_numeric($oh)) {
                            $d = (float) $ch - (float) $oh;
                            $dh = ($d > 0 ? '+' : '') . htmlspecialchars(number_format($d, 2)) . ' cm';
                        }
                        if ($cw !== null && $cw !== '' && is_numeric($cw) && $ow !== null && $ow !== '' && is_numeric($ow)) {
                            $d = (float) $cw - (float) $ow;
                            $dw = ($d > 0 ? '+' : '') . htmlspecialchars(number_format($d, 2)) . ' kg';
                        }
                    }
                    ?>
                    <tr>
                        <td><strong>#<?= (int) $r['id'] ?></strong></td>
                        <td><span class="text-muted"><?= htmlspecialchars((string) $r['created_at']) ?></span></td>
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
                        <td class="small"><?= $dh ?></td>
                        <td class="small"><?= $dw ?></td>
                        <td><?php
                            $err = (string) ($r['error_message'] ?? '');
                            echo htmlspecialchars(strlen($err) > 60 ? substr($err, 0, 57) . '…' : $err);
                        ?></td>
                        <td class="text-end">
                            <a href="<?= htmlspecialchars(sv_append_back('report.php?id=' . (int) $r['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-sm">
                                <i class="fa-solid fa-eye fa-fw"></i> View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$reports): ?>
                    <tr><td colspan="13" class="text-center text-muted py-4">No reports match these filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="p-2 border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span class="text-muted small">Page <?= (int) $page ?> of <?= (int) $totalPages ?></span>
            <?php sv_render_pagination('patient.php', $page, $totalPages); ?>
        </div>
    </div>
</div>
<?php sv_layout_end(); ?>

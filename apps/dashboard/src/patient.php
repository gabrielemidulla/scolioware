<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/pagination.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/back.php';
require_once __DIR__ . '/inc/phi_log.php';

sv_require_auth();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo htmlspecialchars((string) __('patient.err_missing_id'), ENT_QUOTES, 'UTF-8');
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM patients WHERE id = ?');
$stmt->execute([$id]);
$patient = $stmt->fetch();
if (!$patient) {
    http_response_code(404);
    echo htmlspecialchars((string) __('patient.err_not_found'), ENT_QUOTES, 'UTF-8');
    exit;
}

sv_phi_log('view_patient', (int) $patient['id'], null, null);

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

$chartStmt = $pdo->prepare(
    "SELECT id, created_at, status,
            cobb_pt_deg, cobb_mt_deg, cobb_tl_deg,
            cobb_thoracic_deg, cobb_lumbar_deg, cobb_max_deg, cobb_max_region,
            height_cm, weight_kg
     FROM reports
     WHERE patient_id = ? AND status = 'completed'
     ORDER BY created_at ASC, id ASC"
);
$chartStmt->execute([$id]);
$chartRows = $chartStmt->fetchAll();

$chartData = [
    'labels' => [],
    'rids' => [],
    'cobb_pt' => [],
    'cobb_mt' => [],
    'cobb_tl' => [],
    'cobb_max' => [],
    'cobb_max_region' => [],
    'height_cm' => [],
    'weight_kg' => [],
];
foreach ($chartRows as $cr) {
    $chartData['labels'][] = (string) $cr['created_at'];
    $chartData['rids'][] = (int) $cr['id'];
    $chartData['cobb_pt'][] = isset($cr['cobb_pt_deg']) && is_numeric($cr['cobb_pt_deg']) ? (float) $cr['cobb_pt_deg'] : null;
    $chartData['cobb_mt'][] = isset($cr['cobb_mt_deg']) && is_numeric($cr['cobb_mt_deg']) ? (float) $cr['cobb_mt_deg'] : null;
    $chartData['cobb_tl'][] = isset($cr['cobb_tl_deg']) && is_numeric($cr['cobb_tl_deg']) ? (float) $cr['cobb_tl_deg'] : null;
    $chartData['cobb_max'][] = isset($cr['cobb_max_deg']) && is_numeric($cr['cobb_max_deg']) ? (float) $cr['cobb_max_deg'] : null;
    $chartData['cobb_max_region'][] = $cr['cobb_max_region'] !== null ? (string) $cr['cobb_max_region'] : '';
    $chartData['height_cm'][] = isset($cr['height_cm']) && is_numeric($cr['height_cm']) ? (float) $cr['height_cm'] : null;
    $chartData['weight_kg'][] = isset($cr['weight_kg']) && is_numeric($cr['weight_kg']) ? (float) $cr['weight_kg'] : null;
}

$from = $totalReports === 0 ? 0 : $offset + 1;
$to = min($offset + count($reports), $totalReports);
$rowsPart = $totalReports > 0
    ? (string) __('patient.reports_count_rows', ['from' => (int) $from, 'to' => (int) $to])
    : '';
$reportsLine = (string) __('patient.reports_count_wrap', [
    'n' => (int) $totalReports,
    'rows' => $rowsPart,
    'newest' => (string) __('patient.reports_sub'),
]);
$uCm = (string) __('patient.unit_cm');
$uKg = (string) __('patient.unit_kg');

$patientDisplayName = $patient['last_name'] . ', ' . $patient['first_name'];
$genderLabel = sv_t_gender((string) $patient['gender']);

sv_layout_start(
    (string) __('patient.title', ['name' => $patientDisplayName])
);
$patientCrumbLabel = $patientDisplayName;
$headingName = htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name'], ENT_QUOTES, 'UTF-8');
ob_start();
if (sv_back_validate(sv_back_get_raw()) !== null): ?>
            <a href="<?= htmlspecialchars(sv_back_or('patients.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left fa-fw"></i> <?= htmlspecialchars((string) __('patient.back'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php else: ?>
            <a href="patients.php" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left fa-fw"></i> <?= htmlspecialchars((string) __('patient.all'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endif; ?>
        <a href="<?= htmlspecialchars(sv_append_back('new_report.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary">
            <i class="fa-solid fa-file-circle-plus fa-fw"></i> <?= htmlspecialchars((string) __('patient.new_report'), ENT_QUOTES, 'UTF-8') ?>
        </a>
<?php
$topActions = ob_get_clean();
sv_topbar(
    [
        sv_crumb((string) __('nav.dashboard'), 'index.php'),
        sv_crumb((string) __('nav.patients'), 'patients.php'),
        sv_crumb($patientCrumbLabel, null),
    ],
    '<i class="fa-solid fa-user-injured"></i> ' . $headingName,
    $topActions
);
?>

<div class="sv-card mb-3">
    <div class="sv-card-header">
        <i class="fa-solid fa-id-card"></i> <?= htmlspecialchars((string) __('patient.details'), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div class="sv-card-body">
        <div class="row g-2 small">
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars((string) __('patient.id'), ENT_QUOTES, 'UTF-8') ?></span><br><strong>#<?= (int) $patient['id'] ?></strong></div>
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars((string) __('patient.tax'), ENT_QUOTES, 'UTF-8') ?></span><br><code><?= htmlspecialchars($patient['tax_code']) ?></code></div>
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars((string) __('patient.birth'), ENT_QUOTES, 'UTF-8') ?></span><br><?= htmlspecialchars($patient['birth_date']) ?></div>
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars((string) __('patient.gender'), ENT_QUOTES, 'UTF-8') ?></span><br><?= htmlspecialchars($genderLabel) ?></div>
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars((string) __('patient.last_h'), ENT_QUOTES, 'UTF-8') ?></span><br><?php
                $lh = $patient['last_height_cm'] ?? null;
                echo $lh !== null && $lh !== '' && is_numeric($lh) ? '<strong>' . htmlspecialchars(number_format((float) $lh, 2)) . '</strong>' : '<span class="text-muted">' . htmlspecialchars((string) __('common.dash'), ENT_QUOTES, 'UTF-8') . '</span>';
            ?></div>
            <div class="col-md-3"><span class="text-muted"><?= htmlspecialchars((string) __('patient.last_w'), ENT_QUOTES, 'UTF-8') ?></span><br><?php
                $lw = $patient['last_weight_kg'] ?? null;
                echo $lw !== null && $lw !== '' && is_numeric($lw) ? '<strong>' . htmlspecialchars(number_format((float) $lw, 2)) . '</strong>' : '<span class="text-muted">' . htmlspecialchars((string) __('common.dash'), ENT_QUOTES, 'UTF-8') . '</span>';
            ?></div>
        </div>
    </div>
</div>

<div class="sv-card mb-3" id="sv-patient-charts"<?= count($chartRows) === 0 ? ' style="display:none;"' : '' ?>>
    <div class="sv-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span>
            <i class="fa-solid fa-chart-line"></i> <?= htmlspecialchars((string) __('patient.charts_title'), ENT_QUOTES, 'UTF-8') ?>
            <span class="text-muted fw-normal small ms-2"><?= htmlspecialchars(
                (string) __('patient.charts_sub', ['n' => count($chartRows)]),
                ENT_QUOTES,
                'UTF-8'
            ) ?></span>
        </span>
    </div>
    <div class="sv-card-body">
        <div class="row g-3">
            <div class="col-12 col-xl-6">
                <div class="sv-chart-wrap">
                    <canvas id="sv-chart-cobb" aria-label="<?= htmlspecialchars(
                        (string) __('patient.charts_cobb_aria'),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"></canvas>
                </div>
            </div>
            <div class="col-12 col-xl-6">
                <div class="sv-chart-wrap">
                    <canvas id="sv-chart-vitals" aria-label="<?= htmlspecialchars(
                        (string) __('patient.charts_vitals_aria'),
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"></canvas>
                </div>
            </div>
        </div>
        <p class="small text-muted mb-0 mt-2"><?= htmlspecialchars(
            (string) __('patient.charts_help'),
            ENT_QUOTES,
            'UTF-8'
        ) ?></p>
    </div>
</div>

<div class="sv-card sv-filters mb-3">
    <div class="sv-card-header">
        <i class="fa-solid fa-filter"></i> <?= htmlspecialchars((string) __('patient.filter_title'), ENT_QUOTES, 'UTF-8') ?>
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
                <label class="form-label"><?= htmlspecialchars((string) __('queue.status'), ENT_QUOTES, 'UTF-8') ?></label>
                <select class="form-select" name="status">
                    <option value=""><?= htmlspecialchars((string) __('common.all'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($allowedStatus as $st): ?>
                        <option value="<?= htmlspecialchars($st) ?>"<?= $status === $st ? ' selected' : '' ?>><?= htmlspecialchars(sv_t_report_status($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.curve'), ENT_QUOTES, 'UTF-8') ?></label>
                <select class="form-select" name="curve">
                    <option value=""<?= $curve === '' ? ' selected' : '' ?>><?= htmlspecialchars((string) __('curve.filter_all'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="C"<?= $curve === 'C' ? ' selected' : '' ?>><?= htmlspecialchars((string) __('curve.opt_c'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="S"<?= $curve === 'S' ? ' selected' : '' ?>><?= htmlspecialchars((string) __('curve.opt_s'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="unspecified"<?= $curve === 'unspecified' ? ' selected' : '' ?>><?= htmlspecialchars((string) __('curve.opt_unspecified'), ENT_QUOTES, 'UTF-8') ?></option>
                </select>
            </div>

            <div class="col-6 col-md-3 col-lg-1">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.rid'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="rid" value="<?= htmlspecialchars($ridExact) ?>" placeholder="<?= htmlspecialchars((string) __('queue.rid_ph'), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric">
            </div>
            <div class="col-6 col-md-3 col-lg-1">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.rid_min'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="rid_min" value="<?= $ridMin > 0 ? (int) $ridMin : '' ?>" placeholder="<?= htmlspecialchars((string) __('queue.ph_min'), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric">
            </div>
            <div class="col-6 col-md-3 col-lg-1">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.rid_max'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="rid_max" value="<?= $ridMax > 0 ? (int) $ridMax : '' ?>" placeholder="<?= htmlspecialchars((string) __('queue.ph_max'), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric">
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.tor_min'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="tor_min" value="<?= $torMin !== null ? htmlspecialchars((string) $torMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.tor_max'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="tor_max" value="<?= $torMax !== null ? htmlspecialchars((string) $torMax) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.lum_min'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="lum_min" value="<?= $lumMin !== null ? htmlspecialchars((string) $lumMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.lum_max'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="lum_max" value="<?= $lumMax !== null ? htmlspecialchars((string) $lumMax) : '' ?>" inputmode="decimal">
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.cobb_min'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="cobb_min" value="<?= $cobbMin !== null ? htmlspecialchars((string) $cobbMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.cobb_max'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="cobb_max" value="<?= $cobbMax !== null ? htmlspecialchars((string) $cobbMax) : '' ?>" inputmode="decimal">
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.h_min'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="h_cm_min" value="<?= $hCmMin !== null ? htmlspecialchars((string) $hCmMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.h_max'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="h_cm_max" value="<?= $hCmMax !== null ? htmlspecialchars((string) $hCmMax) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.w_min'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="w_kg_min" value="<?= $wKgMin !== null ? htmlspecialchars((string) $wKgMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.w_max'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="w_kg_max" value="<?= $wKgMax !== null ? htmlspecialchars((string) $wKgMax) : '' ?>" inputmode="decimal">
            </div>

            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.created_from'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" type="date" name="created_from" value="<?= htmlspecialchars($createdFrom) ?>">
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label"><?= htmlspecialchars((string) __('queue.created_to'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" type="date" name="created_to" value="<?= htmlspecialchars($createdTo) ?>">
            </div>

            <div class="col-12 d-flex flex-wrap gap-2 align-items-end mt-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-magnifying-glass fa-fw"></i> <?= htmlspecialchars((string) __('queue.apply'), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <a href="<?= htmlspecialchars(sv_back_preserve_on('patient.php?id=' . (int) $id), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                    <i class="fa-solid fa-xmark fa-fw"></i> <?= htmlspecialchars((string) __('queue.clear'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        </form>
        <p class="text-muted small mb-0 mt-2">
            <strong><?= htmlspecialchars((string) __('patient.delta_strong'), ENT_QUOTES, 'UTF-8') ?></strong> <?= htmlspecialchars((string) __('patient.delta_text'), ENT_QUOTES, 'UTF-8') ?>
        </p>
    </div>
</div>

<div class="sv-card">
    <div class="sv-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span>
            <i class="fa-solid fa-file-medical"></i> <?= htmlspecialchars((string) __('patient.reports'), ENT_QUOTES, 'UTF-8') ?>
            <span class="text-muted fw-normal">
                <?= htmlspecialchars($reportsLine, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </span>
        <span class="text-muted small"><?= htmlspecialchars((string) __('patient.per_page_n', ['n' => $perPage]), ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="sv-card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars((string) __('patient.col_report'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars((string) __('patient.col_created'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars((string) __('patient.col_status'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars((string) __('patient.col_curve'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars((string) __('patient.col_tor'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars((string) __('patient.col_lum'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars((string) __('patient.col_max'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars((string) __('patient.col_h'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars((string) __('patient.col_w'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars((string) __('patient.col_dh'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars((string) __('patient.col_dw'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars((string) __('patient.col_err'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $dashCell = htmlspecialchars((string) __('common.dash'), ENT_QUOTES, 'UTF-8');
                foreach ($reports as $ri => $r):
                    $older = $reports[$ri + 1] ?? null;
                    $dh = $dashCell;
                    $dw = $dashCell;
                    if ($older !== null) {
                        $ch = $r['height_cm'] ?? null;
                        $cw = $r['weight_kg'] ?? null;
                        $oh = $older['height_cm'] ?? null;
                        $ow = $older['weight_kg'] ?? null;
                        if ($ch !== null && $ch !== '' && is_numeric($ch) && $oh !== null && $oh !== '' && is_numeric($oh)) {
                            $d = (float) $ch - (float) $oh;
                            $dh = ($d > 0 ? '+' : '') . htmlspecialchars(number_format($d, 2), ENT_QUOTES, 'UTF-8') . htmlspecialchars($uCm, ENT_QUOTES, 'UTF-8');
                        }
                        if ($cw !== null && $cw !== '' && is_numeric($cw) && $ow !== null && $ow !== '' && is_numeric($ow)) {
                            $d = (float) $cw - (float) $ow;
                            $dw = ($d > 0 ? '+' : '') . htmlspecialchars(number_format($d, 2), ENT_QUOTES, 'UTF-8') . htmlspecialchars($uKg, ENT_QUOTES, 'UTF-8');
                        }
                    }
                    $curveCell = (string) ($r['curve_type'] ?? '');
                    if ($curveCell === '') {
                        $curveOut = $dashCell;
                    } else {
                        $curveOut = htmlspecialchars(sv_t_curve_filter($curveCell), ENT_QUOTES, 'UTF-8');
                    }
                ?>
                    <tr>
                        <td><strong>#<?= (int) $r['id'] ?></strong></td>
                        <td><span class="text-muted"><?= htmlspecialchars((string) $r['created_at']) ?></span></td>
                        <td>
                            <span class="<?= sv_status_class($r['status']) ?>">
                                <?= sv_status_icon_markup($r['status']) ?>
                                <span class="sv-status-text"><?= htmlspecialchars(sv_t_report_status((string) $r['status'])) ?></span>
                            </span>
                        </td>
                        <td><?= $curveOut ?></td>
                        <td><?php
                            $v = $r['cobb_thoracic_deg'] ?? null;
                            echo $v !== null && $v !== '' ? htmlspecialchars(number_format((float) $v, 2)) : $dashCell;
                        ?></td>
                        <td><?php
                            $v = $r['cobb_lumbar_deg'] ?? null;
                            echo $v !== null && $v !== '' ? htmlspecialchars(number_format((float) $v, 2)) : $dashCell;
                        ?></td>
                        <td><?php
                            $reg = $r['cobb_max_region'] ?? '';
                            $mx = $r['cobb_max_deg'] ?? null;
                            if ($mx !== null && $mx !== '') {
                                echo htmlspecialchars(strtoupper((string) $reg) . ' ' . number_format((float) $mx, 2) . '°');
                            } else {
                                echo $dashCell;
                            }
                        ?></td>
                        <td><?php
                            $hc = $r['height_cm'] ?? null;
                            echo $hc !== null && $hc !== '' ? htmlspecialchars(number_format((float) $hc, 2)) . htmlspecialchars($uCm, ENT_QUOTES, 'UTF-8') : $dashCell;
                        ?></td>
                        <td><?php
                            $wk = $r['weight_kg'] ?? null;
                            echo $wk !== null && $wk !== '' ? htmlspecialchars(number_format((float) $wk, 2)) . htmlspecialchars($uKg, ENT_QUOTES, 'UTF-8') : $dashCell;
                        ?></td>
                        <td class="small"><?= $dh ?></td>
                        <td class="small"><?= $dw ?></td>
                        <td><?php
                            $err = (string) ($r['error_message'] ?? '');
                            echo htmlspecialchars(strlen($err) > 60 ? substr($err, 0, 57) . '…' : $err);
                        ?></td>
                        <td class="text-end">
                            <a href="<?= htmlspecialchars(sv_append_back('report.php?id=' . (int) $r['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary btn-sm">
                                <i class="fa-solid fa-eye fa-fw"></i> <?= htmlspecialchars((string) __('patient.view'), ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$reports): ?>
                    <tr><td colspan="13" class="text-center text-muted py-4"><?= htmlspecialchars((string) __('patient.empty'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="p-2 border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span class="text-muted small"><?= htmlspecialchars(
                (string) __('patient.page_of', ['page' => (int) $page, 'total' => (int) $totalPages]),
                ENT_QUOTES,
                'UTF-8'
            ) ?></span>
            <?php sv_render_pagination('patient.php', $page, $totalPages); ?>
        </div>
    </div>
</div>
<?php if (count($chartRows) > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>
<script>
    window.__SV_PATIENT_CHARTS = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
    window.__SV_CHART_I18N = <?= json_encode([
        'cobb_title' => (string) __('patient.charts_cobb_title'),
        'vitals_title' => (string) __('patient.charts_vitals_title'),
        'pt' => (string) __('report.cobb_pt'),
        'mt' => (string) __('report.cobb_mt'),
        'tl' => (string) __('report.cobb_tl'),
        'max' => (string) __('report.cobb_max'),
        'height' => (string) __('report.h_cm'),
        'weight' => (string) __('report.w_kg'),
        'angle_deg' => (string) __('patient.charts_yaxis_deg'),
        'cm' => (string) __('patient.unit_cm'),
        'kg' => (string) __('patient.unit_kg'),
        'date' => (string) __('patient.col_created'),
        'report' => (string) __('patient.col_report'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
    document.addEventListener('DOMContentLoaded', function () {
        function init() {
            if (typeof Chart === 'undefined') { window.setTimeout(init, 60); return; }
            var d = window.__SV_PATIENT_CHARTS;
            var t = window.__SV_CHART_I18N;
            if (!d || !d.labels || !d.labels.length) return;

            var labels = d.labels.map(function (s) { return (s || '').slice(0, 10); });
            var primary = '#0f6b6b';
            var accent  = '#138787';
            var amber   = '#b58900';
            var slate   = '#586e75';
            var mid     = '#9aa0a6';

            function hasAny(arr) { return arr && arr.some(function (v) { return v !== null && v !== undefined; }); }
            function ds(label, data, color, dashed) {
                return {
                    label: label,
                    data: data,
                    borderColor: color,
                    backgroundColor: color + '22',
                    pointBackgroundColor: color,
                    pointBorderColor: color,
                    spanGaps: true,
                    tension: 0.25,
                    borderWidth: 2,
                    pointRadius: 3,
                    borderDash: dashed ? [6, 4] : [],
                };
            }

            var cobbDatasets = [];
            if (hasAny(d.cobb_max)) cobbDatasets.push(ds(t.max, d.cobb_max, primary));
            if (hasAny(d.cobb_pt))  cobbDatasets.push(ds(t.pt,  d.cobb_pt,  accent, true));
            if (hasAny(d.cobb_mt))  cobbDatasets.push(ds(t.mt,  d.cobb_mt,  amber,  true));
            if (hasAny(d.cobb_tl))  cobbDatasets.push(ds(t.tl,  d.cobb_tl,  slate,  true));

            var cobbCanvas = document.getElementById('sv-chart-cobb');
            if (cobbCanvas && cobbDatasets.length > 0) {
                new Chart(cobbCanvas.getContext('2d'), {
                    type: 'line',
                    data: { labels: labels, datasets: cobbDatasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { position: 'bottom' },
                            title: { display: true, text: t.cobb_title },
                            tooltip: {
                                callbacks: {
                                    title: function (items) {
                                        if (!items.length) return '';
                                        var i = items[0].dataIndex;
                                        return t.date + ': ' + labels[i] + ' · ' + t.report + ' #' + d.rids[i];
                                    },
                                    label: function (item) {
                                        if (item.parsed.y === null || item.parsed.y === undefined) return null;
                                        return item.dataset.label + ': ' + item.parsed.y.toFixed(2) + '°';
                                    },
                                },
                            },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: { display: true, text: t.angle_deg },
                                ticks: { callback: function (v) { return v + '°'; } },
                            },
                            x: { ticks: { autoSkip: true, maxRotation: 0 } },
                        },
                    },
                });
            }

            var vitalsCanvas = document.getElementById('sv-chart-vitals');
            var vitalsDatasets = [];
            if (hasAny(d.height_cm)) vitalsDatasets.push(Object.assign(ds(t.height, d.height_cm, primary), { yAxisID: 'yH' }));
            if (hasAny(d.weight_kg)) vitalsDatasets.push(Object.assign(ds(t.weight, d.weight_kg, amber),   { yAxisID: 'yW' }));
            if (vitalsCanvas && vitalsDatasets.length > 0) {
                new Chart(vitalsCanvas.getContext('2d'), {
                    type: 'line',
                    data: { labels: labels, datasets: vitalsDatasets },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { position: 'bottom' },
                            title: { display: true, text: t.vitals_title },
                            tooltip: {
                                callbacks: {
                                    title: function (items) {
                                        if (!items.length) return '';
                                        var i = items[0].dataIndex;
                                        return t.date + ': ' + labels[i] + ' · ' + t.report + ' #' + d.rids[i];
                                    },
                                    label: function (item) {
                                        if (item.parsed.y === null || item.parsed.y === undefined) return null;
                                        var unit = item.dataset.yAxisID === 'yH' ? t.cm : t.kg;
                                        return item.dataset.label + ': ' + item.parsed.y.toFixed(2) + unit;
                                    },
                                },
                            },
                        },
                        scales: {
                            yH: {
                                type: 'linear',
                                position: 'left',
                                title: { display: true, text: t.height + ' (' + t.cm + ')' },
                                grid: { drawOnChartArea: true },
                            },
                            yW: {
                                type: 'linear',
                                position: 'right',
                                title: { display: true, text: t.weight + ' (' + t.kg + ')' },
                                grid: { drawOnChartArea: false },
                            },
                            x: { ticks: { autoSkip: true, maxRotation: 0 } },
                        },
                    },
                });
            }
        }
        init();
    });
</script>
<?php endif; ?>
<?php sv_layout_end(); ?>

<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/pagination.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/back.php';
require_once __DIR__ . '/inc/phi_log.php';

sv_require_auth();
sv_phi_log('view_queue', null, null, null);

$pdo = db();

$allowedStatus = ['pending', 'processing', 'completed', 'failed'];

$patientId = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
$tax = sv_get_str_opt('tax');
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

$where = ['1=1'];
$params = [];

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

if ($patientId > 0) {
    $where[] = 'r.patient_id = ?';
    $params[] = $patientId;
}

if ($tax !== '') {
    $where[] = 'p.tax_code LIKE ?';
    $params[] = '%' . sv_like_escape($tax) . '%';
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

$countSql = "SELECT COUNT(*) FROM reports r JOIN patients p ON p.id = r.patient_id WHERE {$whereSql}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalReports = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalReports / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$limitInt = (int) $perPage;
$offsetInt = (int) $offset;

$selectSql = "SELECT r.id, r.patient_id, r.status, r.error_message, r.curve_type, r.created_at,
               r.height_cm, r.weight_kg,
               r.cobb_thoracic_deg, r.cobb_lumbar_deg, r.cobb_max_deg, r.cobb_max_region,
               p.first_name, p.last_name, p.tax_code
        FROM reports r
        JOIN patients p ON p.id = r.patient_id
        WHERE {$whereSql}
        ORDER BY r.id DESC
        LIMIT {$limitInt} OFFSET {$offsetInt}";

$listStmt = $pdo->prepare($selectSql);
$listStmt->execute($params);
$rows = $listStmt->fetchAll();

$from = $totalReports === 0 ? 0 : $offset + 1;
$to = min($offset + count($rows), $totalReports);

$allPatients = $pdo->query(
    'SELECT id, first_name, last_name, tax_code FROM patients ORDER BY last_name ASC, first_name ASC'
)->fetchAll();

$isQueueFragment = (string) ($_GET['_fragment'] ?? '') === '1';
if ($isQueueFragment) {
    unset($_GET['_fragment']);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Scoliosoft-Queue-Fragment: ok');
    require __DIR__ . '/inc/queue_results_partial.php';
    exit;
}

sv_layout_start(__('queue.title'));
ob_start(); ?>
        <a href="new_report.php" class="btn btn-primary">
            <i class="fa-solid fa-file-circle-plus fa-fw"></i> <?= htmlspecialchars(__('queue.new_report'), ENT_QUOTES, 'UTF-8') ?>
        </a>
<?php
$topActions = ob_get_clean();
sv_topbar(
    [
        sv_crumb(__('crumb.dashboard'), 'index.php'),
        sv_crumb(__('queue.title'), null),
    ],
    '<i class="fa-solid fa-list-check"></i> ' . __('queue.title'),
    $topActions
);
?>

<div class="sv-card sv-filters mb-3">
    <div class="sv-card-header">
        <i class="fa-solid fa-filter"></i> <?= htmlspecialchars(__('queue.filters'), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div class="sv-card-body">
        <form method="get" action="queue.php" class="row g-2">
            <input type="hidden" name="page" value="1">
            <?php
            $backRaw = sv_back_get_raw();
            if ($backRaw !== null && sv_back_validate($backRaw) !== null): ?>
                <input type="hidden" name="back" value="<?= htmlspecialchars($backRaw, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>

            <div class="col-md-4 col-lg-3">
                <label class="form-label"><?= htmlspecialchars(__('queue.patient'), ENT_QUOTES, 'UTF-8') ?></label>
                <select class="form-select" name="patient_id">
                    <option value=""><?= htmlspecialchars(__('queue.all_patients'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($allPatients as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"<?= $patientId === (int) $p['id'] ? ' selected' : '' ?>>
                            <?= htmlspecialchars($p['last_name'] . ', ' . $p['first_name'] . ' (' . $p['tax_code'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.tax'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="tax" value="<?= htmlspecialchars($tax) ?>" placeholder="<?= htmlspecialchars(__('queue.tax_ph'), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.status'), ENT_QUOTES, 'UTF-8') ?></label>
                <select class="form-select" name="status">
                    <option value=""><?= htmlspecialchars(__('common.all'), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($allowedStatus as $st): ?>
                        <option value="<?= htmlspecialchars($st) ?>"<?= $status === $st ? ' selected' : '' ?>><?= htmlspecialchars(sv_t_report_status($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.curve'), ENT_QUOTES, 'UTF-8') ?></label>
                <select class="form-select" name="curve">
                    <option value=""<?= $curve === '' ? ' selected' : '' ?>><?= htmlspecialchars(__('curve.filter_all'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="C"<?= $curve === 'C' ? ' selected' : '' ?>><?= htmlspecialchars(__('curve.opt_c'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="S"<?= $curve === 'S' ? ' selected' : '' ?>><?= htmlspecialchars(__('curve.opt_s'), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="unspecified"<?= $curve === 'unspecified' ? ' selected' : '' ?>><?= htmlspecialchars(__('curve.opt_unspecified'), ENT_QUOTES, 'UTF-8') ?></option>
                </select>
            </div>

            <div class="col-6 col-md-3 col-lg-1">
                <label class="form-label"><?= htmlspecialchars(__('queue.rid'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="rid" value="<?= htmlspecialchars($ridExact) ?>" placeholder="<?= htmlspecialchars(__('queue.rid_ph'), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric">
            </div>
            <div class="col-6 col-md-3 col-lg-1">
                <label class="form-label"><?= htmlspecialchars(__('queue.rid_min'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="rid_min" value="<?= $ridMin > 0 ? (int) $ridMin : '' ?>" placeholder="<?= htmlspecialchars(__('queue.ph_min'), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric">
            </div>
            <div class="col-6 col-md-3 col-lg-1">
                <label class="form-label"><?= htmlspecialchars(__('queue.rid_max'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="rid_max" value="<?= $ridMax > 0 ? (int) $ridMax : '' ?>" placeholder="<?= htmlspecialchars(__('queue.ph_max'), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric">
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.tor_min'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="tor_min" value="<?= $torMin !== null ? htmlspecialchars((string) $torMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.tor_max'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="tor_max" value="<?= $torMax !== null ? htmlspecialchars((string) $torMax) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.lum_min'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="lum_min" value="<?= $lumMin !== null ? htmlspecialchars((string) $lumMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.lum_max'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="lum_max" value="<?= $lumMax !== null ? htmlspecialchars((string) $lumMax) : '' ?>" inputmode="decimal">
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.cobb_min'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="cobb_min" value="<?= $cobbMin !== null ? htmlspecialchars((string) $cobbMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.cobb_max'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="cobb_max" value="<?= $cobbMax !== null ? htmlspecialchars((string) $cobbMax) : '' ?>" inputmode="decimal">
            </div>

            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.h_min'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="h_cm_min" value="<?= $hCmMin !== null ? htmlspecialchars((string) $hCmMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.h_max'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="h_cm_max" value="<?= $hCmMax !== null ? htmlspecialchars((string) $hCmMax) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.w_min'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="w_kg_min" value="<?= $wKgMin !== null ? htmlspecialchars((string) $wKgMin) : '' ?>" inputmode="decimal">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.w_max'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" name="w_kg_max" value="<?= $wKgMax !== null ? htmlspecialchars((string) $wKgMax) : '' ?>" inputmode="decimal">
            </div>

            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.created_from'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" type="date" name="created_from" value="<?= htmlspecialchars($createdFrom) ?>">
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <label class="form-label"><?= htmlspecialchars(__('queue.created_to'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" type="date" name="created_to" value="<?= htmlspecialchars($createdTo) ?>">
            </div>

            <div class="col-12 d-flex flex-wrap gap-2 align-items-end mt-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-magnifying-glass fa-fw"></i> <?= htmlspecialchars(__('queue.apply'), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <a href="<?= htmlspecialchars(sv_back_preserve_on('queue.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                    <i class="fa-solid fa-xmark fa-fw"></i> <?= htmlspecialchars(__('queue.clear'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        </form>
    </div>
</div>

<div id="sv-queue-live" data-poll-ms="8000">
<?php require __DIR__ . '/inc/queue_results_partial.php'; ?>
</div>

<script>
(function () {
    var root = document.getElementById('sv-queue-live');
    if (!root) return;
    var ms = parseInt(root.getAttribute('data-poll-ms') || '8000', 10);
    if (ms < 2000) ms = 2000;
    function poll() {
        if (document.hidden) return;
        var u = new URL(window.location.href);
        u.searchParams.set('_fragment', '1');
        fetch(u.toString(), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) {
                if (!r.ok) return Promise.reject();
                if (r.headers.get('X-Scoliosoft-Queue-Fragment') !== 'ok') {
                    return Promise.reject();
                }
                return r.text();
            })
            .then(function (html) { root.innerHTML = html; })
            .catch(function () {});
    }
    setInterval(poll, ms);
})();
</script>
<?php sv_layout_end(); ?>

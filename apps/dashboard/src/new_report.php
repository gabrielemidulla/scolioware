<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/back.php';

sv_require_auth();

$patients = db()->query('SELECT id, first_name, last_name, tax_code FROM patients ORDER BY last_name, first_name')->fetchAll();

sv_layout_start('New report');
sv_topbar(
    [
        sv_crumb('Dashboard', 'index.php'),
        sv_crumb('New report', null),
    ],
    '<i class="fa-solid fa-file-circle-plus"></i> New X-ray report',
    null
);
?>

<?php if (!$patients): ?>
    <div class="sv-card">
        <div class="sv-card-body">
            <p class="mb-0">
                Create a <a href="patients.php">patient</a> first, then come back to enqueue a scan.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="sv-card" style="max-width: 640px;">
        <div class="sv-card-header">
            <i class="fa-solid fa-upload"></i> Enqueue scan
        </div>
        <div class="sv-card-body">
            <div class="mb-3">
                <label class="form-label">Patient</label>
                <select class="form-select" id="patient_id">
                    <?php foreach ($patients as $p): ?>
                        <option value="<?= (int) $p['id'] ?>">
                            <?= htmlspecialchars($p['last_name'] . ', ' . $p['first_name'] . ' (' . $p['tax_code'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">X-ray image</label>
                <input class="form-control" type="file" id="image" accept="image/*">
            </div>
            <button type="button" id="btn" class="btn btn-primary">
                <i class="fa-solid fa-paper-plane fa-fw"></i> Enqueue
            </button>
            <a href="<?= htmlspecialchars(sv_append_back('queue.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                <i class="fa-solid fa-list-check fa-fw"></i> Queue
            </a>
            <div id="msg"></div>
        </div>
    </div>

    <script>
        document.getElementById('btn').onclick = async function () {
            var pid = document.getElementById('patient_id').value;
            var f = document.getElementById('image').files[0];
            var msg = document.getElementById('msg');
            msg.textContent = '';
            if (!f) { msg.textContent = 'Choose an image.'; return; }
            var fd = new FormData();
            fd.append('patient_id', pid);
            fd.append('image', f, f.name);
            try {
                var res = await fetch('/inference/v2/enqueue', { method: 'POST', body: fd });
                var j = await res.json().catch(function () { return {}; });
                if (!res.ok) {
                    var detail = typeof j.detail === 'string' ? j.detail : JSON.stringify(j.detail || j);
                    msg.textContent = 'Error ' + res.status + ': ' + detail
                        + (res.status === 404 ? ' — If this says "Not Found", rebuild the processing container (see docker-compose service name): docker compose build inference && docker compose up -d inference' : '');
                    return;
                }
                msg.textContent = 'Queued report id ' + j.report_id + '. Redirecting…';
                location.href = 'report.php?id=' + encodeURIComponent(j.report_id);
            } catch (e) {
                msg.textContent = String(e);
            }
        };
    </script>
<?php endif; ?>
<?php sv_layout_end(); ?>

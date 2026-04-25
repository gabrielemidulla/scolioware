<?php

declare(strict_types=1);

require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/back.php';

sv_require_auth();

$patients = db()->query('SELECT id, first_name, last_name, tax_code FROM patients ORDER BY last_name, first_name')->fetchAll();

$nrEmpty = (string) __('new_report.empty', [
    'patient_link' => '<a href="patients.php">' . htmlspecialchars((string) __('new_report.patient_link'), ENT_QUOTES, 'UTF-8') . '</a>',
]);
sv_layout_start((string) __('new_report.title'));
sv_topbar(
    [
        sv_crumb((string) __('nav.dashboard'), 'index.php'),
        sv_crumb((string) __('new_report.title'), null),
    ],
    '<i class="fa-solid fa-file-circle-plus"></i> ' . htmlspecialchars((string) __('new_report.heading'), ENT_QUOTES, 'UTF-8'),
    null
);
?>

<?php if (!$patients): ?>
    <div class="sv-card">
        <div class="sv-card-body">
            <p class="mb-0">
                <?= $nrEmpty ?>
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="sv-card" style="max-width: 640px;">
        <div class="sv-card-header">
            <i class="fa-solid fa-upload"></i> <?= htmlspecialchars((string) __('new_report.card'), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="sv-card-body">
            <div class="mb-3">
                <label class="form-label"><?= htmlspecialchars((string) __('new_report.label_patient'), ENT_QUOTES, 'UTF-8') ?></label>
                <select class="form-select" id="patient_id">
                    <?php foreach ($patients as $p): ?>
                        <option value="<?= (int) $p['id'] ?>">
                            <?= htmlspecialchars($p['last_name'] . ', ' . $p['first_name'] . ' (' . $p['tax_code'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label"><?= htmlspecialchars((string) __('new_report.label_image'), ENT_QUOTES, 'UTF-8') ?></label>
                <input class="form-control" type="file" id="image" accept="image/*">
            </div>
            <button type="button" id="btn" class="btn btn-primary">
                <i class="fa-solid fa-paper-plane fa-fw"></i> <?= htmlspecialchars((string) __('new_report.submit'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <a href="<?= htmlspecialchars(sv_append_back('queue.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary">
                <i class="fa-solid fa-list-check fa-fw"></i> <?= htmlspecialchars((string) __('new_report.queue_btn'), ENT_QUOTES, 'UTF-8') ?>
            </a>
            <div id="msg"></div>
        </div>
    </div>

    <script>
        window.__NR_I18N = <?= json_encode([
            'pick' => (string) __('new_report.js_pick'),
            'queued' => (string) __('new_report.js_queued'),
            'err' => (string) __('new_report.js_error'),
            'err404' => (string) __('new_report.js_error_404'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
        document.getElementById('btn').onclick = async function () {
            var I = window.__NR_I18N;
            var pid = document.getElementById('patient_id').value;
            var f = document.getElementById('image').files[0];
            var msg = document.getElementById('msg');
            msg.textContent = '';
            if (!f) { msg.textContent = I.pick; return; }
            var fd = new FormData();
            fd.append('patient_id', pid);
            fd.append('image', f, f.name);
            try {
                var res = await fetch('/inference/v2/enqueue', { method: 'POST', body: fd });
                var j = await res.json().catch(function () { return {}; });
                if (!res.ok) {
                    var detail = typeof j.detail === 'string' ? j.detail : JSON.stringify(j.detail || j);
                    var errLine = I.err
                        .replace('{code}', String(res.status))
                        .replace('{detail}', detail);
                    if (res.status === 404) { errLine += I.err404; }
                    msg.textContent = errLine;
                    return;
                }
                msg.textContent = I.queued.replace('{id}', String(j.report_id));
                location.href = 'report.php?id=' + encodeURIComponent(j.report_id);
            } catch (e) {
                msg.textContent = String(e);
            }
        };
    </script>
<?php endif; ?>
<?php sv_layout_end(); ?>

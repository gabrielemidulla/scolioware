(function () {
'use strict';
    var cfgEl = document.getElementById('sw-report-page-config');
    if (!cfgEl || !cfgEl.textContent) {
        return;
    }
    var cfg = JSON.parse(cfgEl.textContent);
    var I18N = cfg.i18n;
    var reportId = cfg.reportId;
    var CSRF_TOKEN = cfg.csrf_token;

var lastReport = null;
var imageVersion = 0;
var forceImageRefresh = false;
var STATUS_CLASS = {
    pending: 'sw-status sw-status-pending',
    processing: 'sw-status sw-status-processing',
    completed: 'sw-status sw-status-completed',
    failed: 'sw-status sw-status-failed'
};
var STATUS_ICONS = {
    pending: 'fa-clock',
    processing: 'fa-gear',
    completed: 'fa-circle-check',
    failed: 'fa-triangle-exclamation'
};
var imgModal = null;
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('svImgLightbox');
    if (el && typeof bootstrap !== 'undefined') {
        imgModal = new bootstrap.Modal(el);
    }
});
function openImageLightbox(src, title) {
    document.getElementById('svImgLightboxImg').src = src;
    document.getElementById('svImgLightboxImg').alt = title;
    document.getElementById('svImgLightboxTitle').textContent = title;
    if (imgModal) {
        imgModal.show();
    }
}
function wireThumb(wrapId, imgId, title) {
    var wrap = document.getElementById(wrapId);
    var img = document.getElementById(imgId);
    if (!wrap || !img) return;
    function go() {
        if (img.src) openImageLightbox(img.src, title);
    }
    wrap.addEventListener('click', go);
    wrap.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            go();
        }
    });
}
wireThumb('img_orig_wrap', 'img_orig', I18N.js_orig);
wireThumb('img_comp_wrap', 'img_comp', I18N.js_overlay);

function setStatusBadge(el, status) {
    var s = status || '';
    el.className = STATUS_CLASS[s] || 'sw-status sw-status-unknown';
    el.replaceChildren();
    var ic = STATUS_ICONS[s] || 'fa-circle-question';
    var icon = document.createElement('i');
    icon.className = 'fa-solid ' + ic + ' sw-status-icon';
    icon.setAttribute('aria-hidden', 'true');
    var text = document.createElement('span');
    text.className = 'sw-status-text';
    text.textContent = (I18N.status && I18N.status[s]) ? I18N.status[s] : s;
    el.appendChild(icon);
    el.appendChild(text);
}
function fmtDeg(x) {
    if (x === null || x === undefined || x === '') return I18N.dash;
    var n = Number(x);
    return isNaN(n) ? I18N.dash : n.toFixed(2);
}
function fillCobbSummary(j) {
    document.getElementById('cobb_pt').textContent = fmtDeg(j.cobb_pt_deg);
    document.getElementById('cobb_mt').textContent = fmtDeg(j.cobb_mt_deg);
    document.getElementById('cobb_tl').textContent = fmtDeg(j.cobb_tl_deg);
    document.getElementById('cobb_thoracic').textContent = fmtDeg(j.cobb_thoracic_deg);
    document.getElementById('cobb_lumbar').textContent = fmtDeg(j.cobb_lumbar_deg);
    var maxLine = I18N.dash;
    if (j.cobb_max_deg != null && j.cobb_max_deg !== '') {
        var reg = (j.cobb_max_region || '').toString().toUpperCase();
        maxLine = reg + ' ' + fmtDeg(j.cobb_max_deg) + '°';
    }
    document.getElementById('cobb_max').textContent = maxLine;
    var vs = j.cobb_max_vert_superior, vi = j.cobb_max_vert_inferior;
    document.getElementById('cobb_verts').textContent =
        (vs != null && vi != null) ? (String(vs) + ' – ' + String(vi)) : I18N.dash;
}
function setVitals(j) {
    var wrap = document.getElementById('vitals_wrap');
    var el = document.getElementById('vitals');
    if (!wrap || !el) return;
    var h = j.height_cm, w = j.weight_kg;
    if (h != null && h !== '' && w != null && w !== '') {
        el.textContent = Number(h).toFixed(2) + I18N.uCm + ', ' + Number(w).toFixed(2) + I18N.uKg;
        wrap.style.display = '';
    } else {
        el.textContent = I18N.dash;
        wrap.style.display = 'none';
    }
}
function formatCurve(t) {
    if (t == null || t === '') return I18N.dash;
    var c = I18N.curve && I18N.curve[t];
    return c != null && c !== '' ? c : String(t);
}
async function poll() {
    var errEl = document.getElementById('poll_error');
    errEl.style.display = 'none';
    errEl.textContent = '';
    try {
        var res = await fetch('/inference/reports/' + encodeURIComponent(reportId));
        var j = await res.json();
        lastReport = j;
        setStatusBadge(document.getElementById('status'), j.status);
        document.getElementById('curve').textContent = formatCurve(j.curve_type);
        setVitals(j);
        var editBtn = document.getElementById('open_landmark_editor');
        var done = (j.status === 'completed' || j.status === 'failed');
        if (done) {
            fillCobbSummary(j);
            var o = document.getElementById('img_orig');
            var c = document.getElementById('img_comp');
            var compWrap = document.getElementById('img_comp_wrap');
            var q = 'report_image.php?report_id=' + encodeURIComponent(String(reportId)) + '&kind=';
            if (!o.dataset.loaded) {
                o.src = q + 'original';
                o.dataset.loaded = '1';
            }
            document.getElementById('img_orig_wrap').style.display = '';
            var hasComputed = (j.status === 'completed') ||
                (j.computed_object_key != null && String(j.computed_object_key).trim() !== '');
            if (hasComputed) {
                if (!c.dataset.loaded || forceImageRefresh) {
                    imageVersion += 1;
                    c.src = q + 'computed&_v=' + imageVersion;
                    c.dataset.loaded = '1';
                    forceImageRefresh = false;
                }
                compWrap.style.display = '';
            } else {
                compWrap.style.display = 'none';
            }
            // Same minimum as server-side recompute (17 verts × 4 corners × 2 coords).
            var hasLm = Array.isArray(j.landmarks) && j.landmarks.length >= 136;
            if (editBtn) editBtn.style.display = hasLm ? '' : 'none';
            if (j.status === 'completed') {
                ensureLlmCardLoaded();
            }
        } else if (editBtn) {
            editBtn.style.display = 'none';
        }
        var hint = document.getElementById('img_hint');
        if (j.status === 'pending' || j.status === 'processing') {
            hint.textContent = I18N.js_waiting;
        } else if (j.status === 'failed') {
            hint.textContent = j.error_message || I18N.js_failed;
        } else {
            hint.textContent = '';
        }
    } catch (e) {
        errEl.textContent = I18N.js_poll_err + String(e);
        errEl.style.display = 'block';
    }
}
poll();
setInterval(poll, 4000);

/* ---------- Manual landmark editor (Konva) ---------- */
var KONVA_CDN = 'https://cdn.jsdelivr.net/npm/konva@9.3.16/konva.min.js';
var konvaPromise = null;
function loadKonva() {
    if (typeof Konva !== 'undefined') return Promise.resolve();
    if (konvaPromise) return konvaPromise;
    konvaPromise = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = KONVA_CDN;
        s.async = true;
        s.onload = function () { resolve(); };
        s.onerror = function () { konvaPromise = null; reject(new Error('Konva load failed')); };
        document.head.appendChild(s);
    });
    return konvaPromise;
}

var EDITOR = {
    modalEl: null,
    bsModal: null,
    stage: null,
    bgLayer: null,
    ovLayer: null,
    konvaImage: null,
    imgW: 0,
    imgH: 0,
    scale: 1,
    verts: [],
    nodes: [],
    selectedIdx: -1,
    loaded: false,
    dirty: false,
};

function lmkMessage(msg, kind) {
    var el = document.getElementById('lmk_msg');
    if (!el) return;
    if (!msg) { el.style.display = 'none'; el.textContent = ''; return; }
    el.className = 'alert rounded-0 mb-0 py-2 small ' + (kind === 'danger' ? 'alert-danger' : kind === 'success' ? 'alert-success' : 'alert-warning');
    el.textContent = msg;
    el.style.display = '';
}

function flatToVerts(flat) {
    var verts = [];
    if (!Array.isArray(flat) || flat.length < 8) return verts;
    var nKp = 4;
    var n = Math.floor(flat.length / (nKp * 2));
    for (var v = 0; v < n; v++) {
        var pts = [];
        for (var k = 0; k < nKp; k++) {
            var i = 2 * (v * nKp + k);
            pts.push({ x: Number(flat[i]) || 0, y: Number(flat[i + 1]) || 0 });
        }
        verts.push(pts);
    }
    return verts;
}
function vertsToFlat(verts) {
    var out = [];
    for (var v = 0; v < verts.length; v++) {
        for (var k = 0; k < verts[v].length; k++) {
            out.push(Number(verts[v][k].x) || 0);
            out.push(Number(verts[v][k].y) || 0);
        }
    }
    return out;
}

function vertCentroid(p) {
    var cx = 0, cy = 0;
    for (var i = 0; i < p.length; i++) { cx += p[i].x; cy += p[i].y; }
    return { x: cx / p.length, y: cy / p.length };
}

function applySelectionStyles() {
    for (var i = 0; i < EDITOR.nodes.length; i++) {
        var n = EDITOR.nodes[i];
        if (!n) continue;
        var isSel = i === EDITOR.selectedIdx;
        n.quad.stroke(isSel ? '#ffeb3b' : '#00e5ff');
        n.quad.strokeWidth(isSel ? 2.5 : 1.6);
        n.quad.fill(isSel ? 'rgba(255,235,59,0.18)' : 'rgba(0,229,255,0.10)');
        for (var k = 0; k < n.circles.length; k++) {
            n.circles[k].fill(isSel ? '#ffeb3b' : '#ff9800');
            n.circles[k].radius(isSel ? 7 : 5.5);
        }
    }
    if (EDITOR.ovLayer) EDITOR.ovLayer.batchDraw();
}

function selectVert(idx) {
    if (EDITOR.selectedIdx === idx) return;
    EDITOR.selectedIdx = idx;
    var rb = document.getElementById('lmk_remove');
    if (rb) rb.disabled = !(idx >= 0 && EDITOR.verts.length > 2);
    applySelectionStyles();
}

function updateCount() {
    var el = document.getElementById('lmk_count');
    if (!el) return;
    var tmpl = I18N.lmk_count || '{n} vertebrae';
    var n = String(EDITOR.verts.length);
    el.textContent = tmpl.replace(/\{n\}/g, n).replace(/%n%/g, n);
}

function rebuildOverlay() {
    if (!EDITOR.ovLayer) return;
    EDITOR.ovLayer.destroyChildren();
    EDITOR.nodes = [];
    var s = EDITOR.scale;
    var pxOrder = [0, 1, 3, 2];
    // Draw quads/labels first, then handles (z-order).
    for (var v = 0; v < EDITOR.verts.length; v++) {
        var pts = EDITOR.verts[v];
        var poly = [];
        for (var i = 0; i < pxOrder.length; i++) {
            poly.push(pts[pxOrder[i]].x * s, pts[pxOrder[i]].y * s);
        }
        var quad = new Konva.Line({
            points: poly,
            stroke: '#00e5ff',
            strokeWidth: 1.6,
            closed: true,
            fill: 'rgba(0,229,255,0.10)',
            perfectDrawEnabled: false,
            hitStrokeWidth: 0,
            shadowForStrokeEnabled: false,
            listening: true,
            transformsEnabled: 'position',
        });
        (function (vIdx, q) {
            q.on('mousedown touchstart', function () { selectVert(vIdx); });
        })(v, quad);
        EDITOR.ovLayer.add(quad);

        var ctr = vertCentroid(pts);
        var label = new Konva.Text({
            x: ctr.x * s - 10,
            y: ctr.y * s - 8,
            text: String(v + 1),
            fontSize: 13,
            fontStyle: 'bold',
            fill: '#fff',
            listening: false,
            perfectDrawEnabled: false,
            transformsEnabled: 'position',
        });
        EDITOR.ovLayer.add(label);

        EDITOR.nodes.push({ quad: quad, label: label, circles: [] });
    }
    for (var v2 = 0; v2 < EDITOR.verts.length; v2++) {
        (function (vIdx) {
            var pts = EDITOR.verts[vIdx];
            var nodeRef = EDITOR.nodes[vIdx];
            for (var k = 0; k < 4; k++) {
                (function (kpIdx) {
                    var p = pts[kpIdx];
                    var c = new Konva.Circle({
                        x: p.x * s,
                        y: p.y * s,
                        radius: 5.5,
                        fill: '#ff9800',
                        stroke: '#000',
                        strokeWidth: 1,
                        draggable: true,
                        perfectDrawEnabled: false,
                        shadowForStrokeEnabled: false,
                        transformsEnabled: 'position',
                    });
                    c.on('mousedown touchstart', function () { selectVert(vIdx); });
                    c.on('dragstart', function () { c.moveToTop(); });
                    c.on('dragmove', function () {
                        var sNow = EDITOR.scale;
                        EDITOR.verts[vIdx][kpIdx] = {
                            x: c.x() / sNow,
                            y: c.y() / sNow,
                        };
                        EDITOR.dirty = true;
                        var newPoly = [];
                        for (var jj = 0; jj < pxOrder.length; jj++) {
                            newPoly.push(
                                EDITOR.verts[vIdx][pxOrder[jj]].x * sNow,
                                EDITOR.verts[vIdx][pxOrder[jj]].y * sNow
                            );
                        }
                        nodeRef.quad.points(newPoly);
                        var nc = vertCentroid(EDITOR.verts[vIdx]);
                        nodeRef.label.position({ x: nc.x * sNow - 10, y: nc.y * sNow - 8 });
                    });
                    c.on('mouseenter', function () { document.body.style.cursor = 'grab'; });
                    c.on('mouseleave', function () { document.body.style.cursor = ''; });
                    EDITOR.ovLayer.add(c);
                    nodeRef.circles.push(c);
                })(k);
            }
        })(v2);
    }
    applySelectionStyles();
    EDITOR.ovLayer.batchDraw();
    updateCount();
}

function rescaleOverlay() {
    if (!EDITOR.ovLayer) return;
    var s = EDITOR.scale;
    var pxOrder = [0, 1, 3, 2];
    for (var v = 0; v < EDITOR.verts.length; v++) {
        var n = EDITOR.nodes[v];
        if (!n) continue;
        var pts = EDITOR.verts[v];
        var poly = [];
        for (var i = 0; i < pxOrder.length; i++) {
            poly.push(pts[pxOrder[i]].x * s, pts[pxOrder[i]].y * s);
        }
        n.quad.points(poly);
        var ctr = vertCentroid(pts);
        n.label.position({ x: ctr.x * s - 10, y: ctr.y * s - 8 });
        for (var k = 0; k < n.circles.length; k++) {
            n.circles[k].position({ x: pts[k].x * s, y: pts[k].y * s });
        }
    }
    EDITOR.ovLayer.batchDraw();
}

function fitStageToImage() {
    var wrap = document.getElementById('lmk_stage_wrap');
    if (!wrap || !EDITOR.imgW || !EDITOR.imgH) return;
    var availW = Math.max(320, wrap.clientWidth - 16);
    var availH = Math.max(320, wrap.clientHeight - 16);
    var s = Math.min(availW / EDITOR.imgW, availH / EDITOR.imgH);
    if (!isFinite(s) || s <= 0) s = 1;
    EDITOR.scale = s;
    var sw = Math.round(EDITOR.imgW * s);
    var sh = Math.round(EDITOR.imgH * s);
    if (!EDITOR.stage) {
        // Clamp HiDPI so giant retina canvases don’t balloon during dragmove redraws.
        try { Konva.pixelRatio = Math.min(window.devicePixelRatio || 1, 1.5); } catch (_) {}
        EDITOR.stage = new Konva.Stage({ container: 'lmk_stage', width: sw, height: sh });
        EDITOR.bgLayer = new Konva.Layer({ listening: false });
        EDITOR.ovLayer = new Konva.Layer();
        EDITOR.stage.add(EDITOR.bgLayer);
        EDITOR.stage.add(EDITOR.ovLayer);
        EDITOR.stage.on('mousedown touchstart', function (e) {
            if (e.target === EDITOR.stage) selectVert(-1);
        });
    } else {
        EDITOR.stage.size({ width: sw, height: sh });
    }
    if (EDITOR.konvaImage) {
        EDITOR.konvaImage.size({ width: sw, height: sh });
    }
    EDITOR.bgLayer.batchDraw();
    if (EDITOR.nodes && EDITOR.nodes.length === EDITOR.verts.length) {
        rescaleOverlay();
    } else {
        rebuildOverlay();
    }
}

function loadOriginalForEditor() {
    return new Promise(function (resolve, reject) {
        var img = new window.Image();
        img.onload = function () {
            EDITOR.imgW = img.naturalWidth;
            EDITOR.imgH = img.naturalHeight;
            if (EDITOR.bgLayer) {
                EDITOR.bgLayer.destroyChildren();
            }
            EDITOR.konvaImage = new Konva.Image({
                image: img,
                x: 0,
                y: 0,
                listening: false,
                perfectDrawEnabled: false,
                transformsEnabled: 'position',
            });
            fitStageToImage();
            if (EDITOR.bgLayer) {
                EDITOR.bgLayer.add(EDITOR.konvaImage);
                EDITOR.bgLayer.batchDraw();
            }
            resolve();
        };
        img.onerror = function () { reject(new Error('image load failed')); };
        img.src = 'report_image.php?report_id=' + encodeURIComponent(String(reportId)) + '&kind=original';
    });
}

async function openEditor() {
    lmkMessage('');
    try {
        await loadKonva();
    } catch (e) {
        lmkMessage(I18N.lmk_load_konva_err, 'danger');
        return;
    }
    if (!lastReport || !Array.isArray(lastReport.landmarks) || lastReport.landmarks.length < 136) {
        lmkMessage(I18N.lmk_no_orig, 'danger');
        return;
    }
    EDITOR.verts = flatToVerts(lastReport.landmarks);
    EDITOR.selectedIdx = -1;
    EDITOR.dirty = false;
    try {
        await loadOriginalForEditor();
    } catch (e) {
        lmkMessage(I18N.lmk_load_err, 'danger');
        return;
    }
    EDITOR.loaded = true;
    document.getElementById('lmk_remove').disabled = true;
    updateCount();
}

function vertFromCenter(p, dx, dy) {
    return [
        { x: p.x - dx, y: p.y - dy },
        { x: p.x + dx, y: p.y - dy },
        { x: p.x - dx, y: p.y + dy },
        { x: p.x + dx, y: p.y + dy },
    ];
}

function addVertebra() {
    if (EDITOR.verts.length === 0 || !EDITOR.imgW) return;
    var last = EDITOR.verts[EDITOR.verts.length - 1];
    var avg = vertCentroid(last);
    var dx = (Math.abs(last[1].x - last[0].x) || 30) / 2;
    var dy = (Math.abs(last[2].y - last[0].y) || 30) / 2;
    var spacing = dy * 2.4;
    var newCenter = { x: avg.x, y: Math.min(EDITOR.imgH - dy - 2, avg.y + spacing) };
    var pts = vertFromCenter(newCenter, dx, dy);
    var insertAt = EDITOR.selectedIdx >= 0 ? EDITOR.selectedIdx + 1 : EDITOR.verts.length;
    EDITOR.verts.splice(insertAt, 0, pts);
    EDITOR.dirty = true;
    EDITOR.selectedIdx = insertAt;
    rebuildOverlay();
    var rb = document.getElementById('lmk_remove');
    if (rb) rb.disabled = !(EDITOR.selectedIdx >= 0 && EDITOR.verts.length > 2);
}

function removeSelected() {
    if (EDITOR.selectedIdx < 0) return;
    if (EDITOR.verts.length <= 2) {
        lmkMessage(I18N.lmk_min, 'warning');
        return;
    }
    EDITOR.verts.splice(EDITOR.selectedIdx, 1);
    EDITOR.selectedIdx = -1;
    EDITOR.dirty = true;
    document.getElementById('lmk_remove').disabled = true;
    rebuildOverlay();
}

function resetEditor() {
    if (EDITOR.dirty && !window.confirm(I18N.lmk_confirm_reset)) return;
    if (!lastReport || !Array.isArray(lastReport.landmarks)) return;
    EDITOR.verts = flatToVerts(lastReport.landmarks);
    EDITOR.selectedIdx = -1;
    EDITOR.dirty = false;
    document.getElementById('lmk_remove').disabled = true;
    rebuildOverlay();
    lmkMessage('');
}

async function saveEditor() {
    if (EDITOR.verts.length < 2) {
        lmkMessage(I18N.lmk_min, 'warning');
        return;
    }
    var saveBtn = document.getElementById('lmk_save');
    var origLabel = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + I18N.lmk_saving;
    lmkMessage('');
    try {
        var flat = vertsToFlat(EDITOR.verts);
        var res = await fetch('recompute_landmarks.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                csrf_token: CSRF_TOKEN,
                report_id: reportId,
                landmarks: flat,
            }),
        });
        var j = null;
        try { j = await res.json(); } catch (_) {}
        if (!res.ok || !j || !j.ok) {
            var err = (j && j.error) ? j.error : ('HTTP ' + res.status);
            lmkMessage(err, 'danger');
            return;
        }
        EDITOR.dirty = false;
        lmkMessage(I18N.lmk_save_ok, 'success');
        forceImageRefresh = true;
        if (EDITOR.bsModal) EDITOR.bsModal.hide();
        poll();
    } catch (e) {
        lmkMessage(String(e && e.message ? e.message : e), 'danger');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = origLabel;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    EDITOR.modalEl = document.getElementById('svLandmarkEditor');
    if (!EDITOR.modalEl || typeof bootstrap === 'undefined') return;
    EDITOR.bsModal = new bootstrap.Modal(EDITOR.modalEl);
    EDITOR.modalEl.addEventListener('shown.bs.modal', function () {
        openEditor();
    });
    EDITOR.modalEl.addEventListener('hide.bs.modal', function (ev) {
        if (EDITOR.dirty && !window.confirm(I18N.lmk_confirm_close)) {
            ev.preventDefault();
            return;
        }
    });
    EDITOR.modalEl.addEventListener('hidden.bs.modal', function () {
        if (EDITOR.stage) {
            try { EDITOR.stage.destroy(); } catch (_) {}
        }
        EDITOR.stage = null;
        EDITOR.bgLayer = null;
        EDITOR.ovLayer = null;
        EDITOR.konvaImage = null;
        EDITOR.nodes = [];
        EDITOR.loaded = false;
        EDITOR.dirty = false;
        EDITOR.selectedIdx = -1;
        EDITOR.verts = [];
        lmkMessage('');
        var rb = document.getElementById('lmk_remove');
        if (rb) rb.disabled = true;
    });
    document.getElementById('lmk_add').addEventListener('click', addVertebra);
    document.getElementById('lmk_remove').addEventListener('click', removeSelected);
    document.getElementById('lmk_reset').addEventListener('click', resetEditor);
    document.getElementById('lmk_save').addEventListener('click', saveEditor);
    window.addEventListener('resize', function () {
        if (EDITOR.loaded) fitStageToImage();
    });
});

/* ---------- AI preliminary description ---------- */
// Generation is now async on the inference-worker-llm side. POST enqueues
// and returns 202 with { job:{id, status} }; we then poll GET every
// LLM_POLL_MS until either a newer draft.id appears or job.status === 'failed'.
var LLM = {
    loaded: false,
    busy: false,
    hasDraft: false,
    lastDraftId: 0,
    pollTimer: null,
};
var LLM_POLL_MS = 2000;
// 30 minutes hard cap (matches the active-job key TTL on the inference side).
var LLM_POLL_MAX = (30 * 60 * 1000) / LLM_POLL_MS;

function llmStopPolling() {
    if (LLM.pollTimer) {
        clearTimeout(LLM.pollTimer);
        LLM.pollTimer = null;
    }
}

function llmStatusForJob(job) {
    if (!job || !job.status) return I18N.llm_generating || '';
    if (job.status === 'queued' || job.status === 'deferred' || job.status === 'scheduled') {
        return I18N.llm_queued || I18N.llm_generating || '';
    }
    if (job.status === 'started') {
        return I18N.llm_running || I18N.llm_generating || '';
    }
    return I18N.llm_generating || '';
}

function llmFmtDate(s) {
    if (!s) return '';
    try {
        var d = new Date(s);
        if (isNaN(d.getTime())) return String(s);
        return d.toLocaleString();
    } catch (_) { return String(s); }
}

function llmFormatMeta(d) {
    if (!d || typeof d !== 'object') return '';
    var model = String(d.model_tag != null ? d.model_tag : d.modelTag || '').trim();
    var when = llmFmtDate(d.created_at != null ? d.created_at : d.createdAt);
    var secs = ((Number(d.latency_ms != null ? d.latency_ms : d.latencyMs) || 0) / 1000).toFixed(1);
    var parts = [];
    if (model) parts.push(model);
    if (when) parts.push(when);
    parts.push(secs + 's');
    var fallback = parts.join(' · ');
    var tmpl = String(I18N.llm_meta || '').trim();
    if (!tmpl) return fallback;
    var out = tmpl
        .replace(/\{model\}/g, model).replace(/%model%/g, model)
        .replace(/\{when\}/g, when).replace(/%when%/g, when)
        .replace(/\{secs\}/g, secs).replace(/%secs%/g, secs);
    // If anything still looks like an unreplaced token (old cache, odd locale file), use fallback.
    if (/%[a-z_]+%/i.test(out) || /\{[a-z_]+\}/i.test(out)) return fallback;
    return out;
}

function llmRender(draft) {
    var card = document.getElementById('llm_card');
    var empty = document.getElementById('llm_empty');
    var body = document.getElementById('llm_draft');
    var meta = document.getElementById('llm_meta');
    var btnLabel = document.getElementById('llm_btn_label');
    if (!card) return;
    card.style.display = '';
    if (draft && draft.response_text) {
        empty.style.display = 'none';
        body.style.display = '';
        body.textContent = String(draft.response_text);
        meta.textContent = llmFormatMeta(draft);
        btnLabel.textContent = I18N.llm_regenerate || I18N.llm_generate || 'Regenerate';
        LLM.hasDraft = true;
        LLM.lastDraftId = Number(draft.id || 0) || LLM.lastDraftId;
    } else {
        empty.style.display = '';
        body.style.display = 'none';
        body.textContent = '';
        meta.textContent = '';
        btnLabel.textContent = I18N.llm_generate || 'Generate';
        LLM.hasDraft = false;
    }
}

function llmShowError(msg) {
    var el = document.getElementById('llm_error');
    if (!el) return;
    if (msg) {
        el.textContent = String(msg);
        el.style.display = '';
    } else {
        el.textContent = '';
        el.style.display = 'none';
    }
}

function llmShowStatus(msg) {
    var el = document.getElementById('llm_status');
    if (!el) return;
    if (msg) { el.textContent = String(msg); el.style.display = ''; }
    else { el.textContent = ''; el.style.display = 'none'; }
}

async function llmFetchState() {
    var res = await fetch('generate_draft_impression.php?report_id=' + encodeURIComponent(String(reportId)), {
        headers: { 'Accept': 'application/json' },
    });
    var j = await res.json();
    if (!res.ok) throw new Error((j && j.error) || ('HTTP ' + res.status));
    return j || {};
}

async function ensureLlmCardLoaded() {
    var card = document.getElementById('llm_card');
    if (card) card.style.display = '';
    if (LLM.busy || LLM.pollTimer) return;
    if (LLM.loaded && LLM.hasDraft) return;
    try {
        var j = await llmFetchState();
        if (!LLM.loaded) LLM.loaded = true;
        var draft = j && j.draft ? j.draft : null;
        if (draft && draft.response_text) {
            var newId = Number(draft.id || 0) || 0;
            if (newId > LLM.lastDraftId || !LLM.hasDraft) {
                llmRender(draft);
            }
        } else if (!LLM.hasDraft) {
            llmRender(null);
        }
        // Auto-enqueued draft (or tab resume): job may appear after the first fetch.
        var job = j && j.job ? j.job : null;
        if (job && (job.status === 'queued' || job.status === 'started' || job.status === 'deferred' || job.status === 'scheduled')) {
            llmBeginPolling();
        }
    } catch (e) {
        LLM.loaded = false;
        llmShowError(I18N.llm_load_err + ' ' + String(e.message || e));
    }
}

function llmBeginPolling() {
    llmStopPolling();
    LLM.busy = true;
    var btn = document.getElementById('llm_generate');
    if (btn) btn.disabled = true;
    llmShowStatus(I18N.llm_generating || '');

    var ticks = 0;
    var prevDraftId = LLM.lastDraftId;
    async function tick() {
        LLM.pollTimer = null;
        ticks++;
        try {
            var j = await llmFetchState();
            var draft = j && j.draft ? j.draft : null;
            var job = j && j.job ? j.job : null;
            var newDraftId = draft ? (Number(draft.id || 0) || 0) : 0;

            // Done: a fresh draft (newer than the one we had before POST) was persisted.
            if (newDraftId > prevDraftId) {
                llmRender(draft);
                llmStopPolling();
                llmShowStatus('');
                LLM.busy = false;
                if (btn) btn.disabled = false;
                return;
            }

            if (job) {
                if (job.status === 'failed') {
                    llmStopPolling();
                    llmShowStatus('');
                    LLM.busy = false;
                    if (btn) btn.disabled = false;
                    llmShowError((I18N.llm_gen_err || 'Error: ') + String(job.error || 'failed'));
                    return;
                }
                if (job.status === 'finished' && newDraftId <= prevDraftId) {
                    // Worker reports done but no fresh draft row yet — give the DB write a tick.
                    // Fall through to scheduling another poll.
                }
                llmShowStatus(llmStatusForJob(job));
            }

            if (ticks >= LLM_POLL_MAX) {
                llmStopPolling();
                llmShowStatus('');
                LLM.busy = false;
                if (btn) btn.disabled = false;
                llmShowError((I18N.llm_gen_err || 'Error: ') + 'timed out waiting for draft.');
                return;
            }
        } catch (e) {
            // Transient errors don't abort the loop; we just surface them and try again.
            llmShowError(I18N.llm_load_err + ' ' + String(e.message || e));
        }
        LLM.pollTimer = setTimeout(tick, LLM_POLL_MS);
    }
    LLM.pollTimer = setTimeout(tick, LLM_POLL_MS);
}

async function llmGenerate() {
    if (LLM.busy) return;
    if (LLM.hasDraft && I18N.llm_confirm_regen && !window.confirm(I18N.llm_confirm_regen)) return;
    LLM.busy = true;
    var btn = document.getElementById('llm_generate');
    if (btn) btn.disabled = true;
    llmShowError('');
    llmShowStatus(I18N.llm_queued || I18N.llm_generating || '');
    try {
        var res = await fetch('generate_draft_impression.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF_TOKEN, report_id: reportId }),
        });
        var j = await res.json();
        // 202 Accepted is the normal happy path; any non-2xx is an error.
        if (!res.ok || !j || j.ok !== true) {
            throw new Error((j && j.error) || ('HTTP ' + res.status));
        }
        llmBeginPolling();
    } catch (e) {
        llmShowStatus('');
        llmShowError((I18N.llm_gen_err || 'Error: ') + String(e.message || e));
        if (btn) btn.disabled = false;
        LLM.busy = false;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('llm_generate');
    if (btn) btn.addEventListener('click', llmGenerate);
});

})();

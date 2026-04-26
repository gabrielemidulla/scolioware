"""RQ `sv_reports` worker: keypoint pipeline, then optional auto LLM job on `sv_llm`."""

from __future__ import annotations

import io

import cv2 as cv
import numpy as np
import structlog
from PIL import Image

from src import persistence, storage_r2
from src.kprcnn import kprcnn_to_api_format, predict
from src.render_computed import render_computed_overlay
from src.report_derived import build_report_derived

log = structlog.get_logger("worker")


def _process_report_core(report_id: int, patient_id: int, original_key: str) -> bool:
    """True if marked completed; False on detection/Cobb failure."""
    raw = storage_r2.get_bytes(original_key)
    pil = Image.open(io.BytesIO(raw)).convert("RGB")
    image_bgr = cv.cvtColor(np.array(pil), cv.COLOR_RGB2BGR)

    bboxes, keypoints, scores = predict(image_bgr)[0]
    api = kprcnn_to_api_format(bboxes, keypoints, scores, image_bgr.shape)

    cobb_err = api.get("cobb_error")
    if api.get("angles") is None or cobb_err is not None:
        msg = str(cobb_err) if cobb_err else "Cobb angle computation failed; no angles to store."
        persistence.mark_failed(
            report_id, (str(msg) + "\nReport processing aborted after detection.")[:65000]
        )
        return False

    jpeg_bytes = render_computed_overlay(image_bgr, api)
    computed_key = storage_r2.object_key_computed(patient_id, report_id)
    storage_r2.put_bytes(computed_key, jpeg_bytes, content_type="image/jpeg")

    derived = build_report_derived(api, image_bgr.shape)
    persistence.mark_completed(
        report_id,
        computed_key,
        api.get("detections"),
        api.get("landmarks"),
        api.get("angles"),
        api.get("midpoint_lines"),
        api.get("curve_type"),
        derived=derived,
    )
    return True


def process_report_by_id(report_id: int) -> None:
    """Idempotent: skips completed/failed or missing original."""
    log.info("worker_claimed", report_id=int(report_id))
    row = persistence.fetch_report(int(report_id))
    if not row:
        log.warning("worker_unknown_report", report_id=int(report_id))
        return
    st = (row.get("status") or "").strip()
    okey = row.get("original_object_key")
    if not okey:
        persistence.mark_failed(int(report_id), "Report has no original image; cannot process.")
        return
    if st == "pending":
        if not persistence.try_mark_processing(int(report_id)):
            log.info("worker_skip_not_pending_anymore", report_id=int(report_id))
            return
    elif st != "processing":
        log.info("worker_skip_terminal_status", report_id=int(report_id), status=st)
        return
    try:
        completed = _process_report_core(int(report_id), int(row["patient_id"]), str(okey))
        if completed:
            try:
                from src.llm_jobs import enqueue_llm_draft

                enqueue_llm_draft(int(report_id), None)
                log.info("llm_draft_auto_enqueued", report_id=int(report_id))
            except Exception as e:
                log.warning(
                    "llm_draft_auto_enqueue_failed",
                    report_id=int(report_id),
                    error=str(e),
                )
    except Exception as e:
        log.error("worker_failed", report_id=int(report_id), error=str(e))
        persistence.mark_failed(int(report_id), f"Worker error: {e!s}"[:60000])
        raise



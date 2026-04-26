"""RQ worker: report pipeline + optional LLM draft enqueue."""

from __future__ import annotations

import io

import cv2 as cv
import numpy as np
import structlog
from PIL import Image

from src.db import persistence
from src.storage import dashboard_storage
from src.vision.render_computed import render_computed_overlay
from src.vision.report_derived import build_report_derived
from src.vision.spine_net_infer import build_inference_api, predict

log = structlog.get_logger("worker")


def _process_report_core(report_id: int, patient_id: int, original_key: str) -> bool:
    raw = dashboard_storage.get_bytes(original_key)
    pil = Image.open(io.BytesIO(raw)).convert("RGB")
    image_bgr = cv.cvtColor(np.array(pil), cv.COLOR_RGB2BGR)

    bboxes, keypoints, scores = predict(image_bgr)[0]
    api = build_inference_api(bboxes, keypoints, scores, image_bgr.shape)

    cobb_err = api.get("cobb_error")
    if api.get("angles") is None or cobb_err is not None:
        msg = str(cobb_err) if cobb_err else "Cobb angle computation failed; no angles to store."
        h, w = int(image_bgr.shape[0]), int(image_bgr.shape[1])
        if api.get("landmarks") or api.get("detections"):
            try:
                persistence.store_inference_partial_artifacts(
                    report_id,
                    detections=api.get("detections"),
                    landmarks=api.get("landmarks"),
                    midpoint_lines=api.get("midpoint_lines"),
                    curve_type=api.get("curve_type"),
                    image_width=w,
                    image_height=h,
                )
            except Exception as e:
                log.warning(
                    "partial_artifacts_save_failed",
                    report_id=int(report_id),
                    error=str(e),
                )
        persistence.mark_failed(
            report_id, (str(msg) + "\nReport processing aborted after detection.")[:65000]
        )
        return False

    jpeg_bytes = render_computed_overlay(image_bgr, api)
    computed_key = dashboard_storage.object_key_computed(patient_id, report_id)
    dashboard_storage.put_bytes(computed_key, jpeg_bytes, content_type="image/jpeg")

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
                from src.llm.llm_jobs import enqueue_llm_draft

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



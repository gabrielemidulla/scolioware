import io
import threading
import time
import traceback

import cv2 as cv
import numpy as np
from PIL import Image

from src import persistence, storage_r2
from src.kprcnn import kprcnn_to_api_format, predict
from src.report_derived import build_report_derived
from src.render_computed import render_computed_overlay


def _process_report(report_id: int, patient_id: int, original_key: str) -> None:
    raw = storage_r2.get_bytes(original_key)
    pil = Image.open(io.BytesIO(raw)).convert("RGB")
    image_bgr = cv.cvtColor(np.array(pil), cv.COLOR_RGB2BGR)

    bboxes, keypoints, scores = predict(image_bgr)[0]
    api = kprcnn_to_api_format(bboxes, keypoints, scores, image_bgr.shape)

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


def _loop(stop: threading.Event) -> None:
    while not stop.is_set():
        row = None
        try:
            if not storage_r2.r2_configured():
                time.sleep(2.0)
                continue
            row = persistence.claim_next_pending()
            if not row:
                time.sleep(1.0)
                continue
            rid = int(row["id"])
            pid = int(row["patient_id"])
            okey = row["original_object_key"]
            _process_report(rid, pid, okey)
        except Exception as e:
            tb = traceback.format_exc()
            if row is not None:
                try:
                    persistence.mark_failed(int(row["id"]), f"{e!s}\n{tb}"[:60000])
                except Exception:
                    pass
            time.sleep(1.0)


def start_worker() -> threading.Event:
    stop = threading.Event()
    t = threading.Thread(target=_loop, args=(stop,), name="report-worker", daemon=True)
    t.start()
    return stop

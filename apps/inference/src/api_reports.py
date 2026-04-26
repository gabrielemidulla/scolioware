import json
import re
from decimal import Decimal
from typing import Any

import structlog
from fastapi import APIRouter, File, Form, HTTPException, UploadFile

from src import persistence, storage_r2
from src.dicom_ingest import decode_upload_to_jpeg
from src.rq_tasks import enqueue_process_report

log = structlog.get_logger("api_reports")

router = APIRouter(tags=["reports"])

_REPORT_EXTRA_FIELDS = (
    "cobb_pt_deg",
    "cobb_mt_deg",
    "cobb_tl_deg",
    "cobb_thoracic_deg",
    "cobb_lumbar_deg",
    "cobb_max_region",
    "cobb_max_deg",
    "cobb_max_vert_superior",
    "cobb_max_vert_inferior",
    "image_width",
    "image_height",
)


def _json_scalar(v: Any) -> Any:
    if v is None:
        return None
    if isinstance(v, Decimal):
        return float(v)
    return v


def _optional_vital_cm_kg(raw: str | None, label: str, lo: float, hi: float) -> float | None:
    if raw is None or not str(raw).strip():
        return None
    s = str(raw).strip().replace(",", ".")
    if not re.match(r"^\d+(\.\d+)?$", s):
        raise HTTPException(status_code=400, detail=f"{label} must be a number.")
    v = float(s)
    if not (lo <= v <= hi):
        raise HTTPException(status_code=400, detail=f"{label} is out of allowed range.")
    return round(v, 2)


def _loads(v: Any) -> Any:
    if v is None:
        return None
    if isinstance(v, (dict, list)):
        return v
    if isinstance(v, (bytes, bytearray)):
        v = v.decode("utf-8")
    return json.loads(v)


async def enqueue_scan(
    patient_id: int = Form(...),
    image: UploadFile = File(...),
    height_cm: str = Form(""),
    weight_kg: str = Form(""),
):
    if not storage_r2.r2_configured():
        raise HTTPException(status_code=503, detail="R2 storage is not configured on the server.")
    if not persistence.patient_exists(patient_id):
        raise HTTPException(
            status_code=400,
            detail="Unknown patient_id: create the patient in the dashboard first, then retry.",
        )

    h = _optional_vital_cm_kg(height_cm, "Height (cm)", 50.0, 250.0)
    w = _optional_vital_cm_kg(weight_kg, "Weight (kg)", 10.0, 350.0)

    body = await image.read()
    if not body:
        raise HTTPException(status_code=400, detail="Empty image upload.")

    report_id = persistence.insert_report_pending(patient_id, height_cm=h, weight_kg=w)
    key = storage_r2.object_key_original(patient_id, report_id)

    image_name = (getattr(image, "filename", None) or "") or "upload"
    try:
        jpeg, dicom_meta = decode_upload_to_jpeg(body, image_name)
    except Exception as e:
        persistence.mark_failed(report_id, f"Invalid image or DICOM: {e!s}")
        raise HTTPException(
            status_code=400,
            detail="Could not decode the file as a raster image or DICOM.",
        ) from e
    try:
        storage_r2.put_bytes(key, jpeg, content_type="image/jpeg")
        persistence.set_original_key(report_id, key)
    except Exception as e:
        persistence.mark_failed(report_id, f"R2 upload failed: {e!s}")
        raise HTTPException(
            status_code=502, detail="Could not upload to object storage."
        ) from e

    if dicom_meta:
        try:
            persistence.set_dicom_metadata(report_id, dicom_meta)
        except Exception as e:
            log.warning("set_dicom_metadata_failed", report_id=report_id, error=str(e))

    try:
        enqueue_process_report(report_id)
    except Exception as e:
        log.error("rq_enqueue_failed", report_id=report_id, error=str(e))
        try:
            persistence.mark_failed(
                report_id,
                f"Could not enqueue report for processing (Redis unavailable): {e!s}",
            )
        except Exception:
            pass
        raise HTTPException(
            status_code=503,
            detail="Background queue unavailable. Try again in a moment.",
        ) from e

    try:
        persistence.refresh_patient_last_vitals(patient_id)
    except Exception as e:
        log.warning("refresh_patient_last_vitals_failed", patient_id=patient_id, error=str(e))

    return {
        "report_id": report_id,
        "patient_id": patient_id,
        "status": "pending",
    }


router.add_api_route("/enqueue", enqueue_scan, methods=["POST"])
router.add_api_route("/v2/enqueue", enqueue_scan, methods=["POST"])


async def get_report(report_id: int):
    row = persistence.fetch_report(report_id)
    if not row:
        raise HTTPException(status_code=404, detail="Report not found.")

    out: dict[str, Any] = {
        "id": row["id"],
        "patient_id": row["patient_id"],
        "status": row["status"],
        "error_message": row.get("error_message"),
        "original_object_key": row.get("original_object_key"),
        "computed_object_key": row.get("computed_object_key"),
        "curve_type": row.get("curve_type"),
    }
    for k in ("height_cm", "weight_kg"):
        if k in row and row[k] is not None:
            out[k] = _json_scalar(row[k])

    if row["status"] == "completed":
        out["detections"] = _loads(row.get("detections_json"))
        out["landmarks"] = _loads(row.get("landmarks_json"))
        out["angles"] = _loads(row.get("angles_json"))
        out["midpoint_lines"] = _loads(row.get("midpoint_lines_json"))
        for k in _REPORT_EXTRA_FIELDS:
            if k in row:
                out[k] = _json_scalar(row[k])
        if row.get("report_metadata_json"):
            out["report_metadata"] = _loads(row.get("report_metadata_json"))
        if row.get("dicom_metadata_json"):
            out["dicom_metadata"] = _loads(row.get("dicom_metadata_json"))
    return out


router.add_api_route("/reports/{report_id}", get_report, methods=["GET"])

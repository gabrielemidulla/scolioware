from __future__ import annotations

import io
from typing import Any

import cv2 as cv
import numpy as np
import structlog
from fastapi import APIRouter, HTTPException, Request, Response
from PIL import Image

from src.db import persistence
from src.llm.llm_jobs import enqueue_llm_draft, get_active_job_state
from src.storage import dashboard_storage
from src.vision.landmark_recompute import recompute_from_flat_landmarks
from src.vision.render_computed import render_computed_overlay
from src.vision.report_derived import build_report_derived

router = APIRouter(tags=["internal"])
log = structlog.get_logger("api_internal")


_MIN_LANDMARK_FLOATS = 17 * 4 * 2


def _parse_flat_landmarks(raw: Any) -> list[float] | None:
    if raw is None:
        return None
    if (
        isinstance(raw, list)
        and len(raw) >= _MIN_LANDMARK_FLOATS
        and len(raw) % 8 == 0
        and all(isinstance(x, (int, float)) for x in raw)
    ):
        return [float(x) for x in raw]
    return None


@router.post("/internal/reports/{report_id}/recompute-landmarks")
async def internal_recompute_landmarks(
    report_id: int,
    request: Request,
) -> dict[str, Any]:
    if not dashboard_storage.storage_proxy_configured():
        raise HTTPException(
            status_code=503,
            detail="Dashboard storage proxy is not configured (DASHBOARD_STORAGE_URL).",
        )
    try:
        body: dict[str, Any] = await request.json()
    except Exception as e:
        raise HTTPException(status_code=400, detail="Invalid JSON body.") from e
    flat = _parse_flat_landmarks(body.get("landmarks"))
    if not flat:
        raise HTTPException(
            status_code=400,
            detail=(
                "Body must include 'landmarks': a JSON array of at least "
                f"{_MIN_LANDMARK_FLOATS} numbers (flat x,y list, 4 corners per vertebra)."
            ),
        )
    phys_raw = body.get("physician_id")
    physician_id: int | None = None
    if phys_raw is not None and str(phys_raw).strip() != "":
        try:
            physician_id = int(phys_raw)
        except (TypeError, ValueError):
            raise HTTPException(status_code=400, detail="Invalid physician_id.") from None

    row = persistence.fetch_report(report_id)
    if not row:
        raise HTTPException(status_code=404, detail="Report not found.")
    okey = row.get("original_object_key")
    if not okey:
        raise HTTPException(status_code=400, detail="Report has no original image key.")

    try:
        raw = dashboard_storage.get_bytes(str(okey))
    except Exception as e:
        raise HTTPException(
            status_code=502, detail=f"Could not read original from storage: {e!s}"
        ) from e

    pil = Image.open(io.BytesIO(raw)).convert("RGB")
    image_bgr = cv.cvtColor(np.array(pil), cv.COLOR_RGB2BGR)

    api, err = recompute_from_flat_landmarks(flat, image_bgr)
    if err is not None:
        raise HTTPException(
            status_code=400, detail=err
        ) from None

    jpeg_bytes = render_computed_overlay(image_bgr, api)
    pid = int(row["patient_id"])
    computed_key = dashboard_storage.object_key_computed(pid, report_id)
    try:
        dashboard_storage.put_bytes(computed_key, jpeg_bytes, content_type="image/jpeg")
    except Exception as e:
        raise HTTPException(
            status_code=502, detail=f"Could not write computed image: {e!s}"
        ) from e

    try:
        persistence.insert_landmark_revision(
            report_id, flat, physician_id, "manual_json"
        )
    except Exception as e:
        log.exception(
            "insert_landmark_revision_failed",
            report_id=report_id,
            error=str(e),
        )
        raise HTTPException(
            status_code=500,
            detail="Could not record landmark revision; the report was not updated.",
        ) from e

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
    return {"ok": True, "report_id": report_id, "status": "completed"}


def _draft_to_dict(d: dict[str, Any]) -> dict[str, Any]:
    created = d.get("created_at")
    if hasattr(created, "isoformat"):
        created = created.isoformat()
    return {
        "id": int(d["id"]),
        "report_id": int(d["report_id"]),
        "physician_id": int(d["physician_id"]) if d.get("physician_id") is not None else None,
        "model_tag": str(d.get("model_tag") or ""),
        "prompt_version": str(d.get("prompt_version") or ""),
        "response_text": str(d.get("response_text") or ""),
        "latency_ms": int(d.get("latency_ms") or 0),
        "prompt_tokens": int(d["prompt_tokens"]) if d.get("prompt_tokens") is not None else None,
        "completion_tokens": (
            int(d["completion_tokens"]) if d.get("completion_tokens") is not None else None
        ),
        "created_at": created,
    }


@router.get("/internal/reports/{report_id}/llm-impression")
async def internal_get_llm_impression(report_id: int) -> dict[str, Any]:
    row = persistence.fetch_report(report_id)
    if not row:
        raise HTTPException(status_code=404, detail="Report not found.")
    latest = persistence.fetch_latest_llm_draft(report_id)
    job = get_active_job_state(report_id)
    return {
        "ok": True,
        "report_id": report_id,
        "draft": _draft_to_dict(latest) if latest else None,
        "job": job,
    }


@router.post("/internal/reports/{report_id}/llm-impression")
async def internal_create_llm_impression(
    report_id: int,
    request: Request,
    response: Response,
) -> dict[str, Any]:
    if not dashboard_storage.storage_proxy_configured():
        raise HTTPException(
            status_code=503,
            detail="Dashboard storage proxy is not configured (DASHBOARD_STORAGE_URL).",
        )

    try:
        body: dict[str, Any] = await request.json()
    except Exception:
        body = {}

    phys_raw = body.get("physician_id")
    physician_id: int | None = None
    if phys_raw is not None and str(phys_raw).strip() != "":
        try:
            physician_id = int(phys_raw)
        except (TypeError, ValueError):
            raise HTTPException(status_code=400, detail="Invalid physician_id.") from None

    row = persistence.fetch_report(report_id)
    if not row:
        raise HTTPException(status_code=404, detail="Report not found.")
    if (row.get("status") or "") != "completed":
        raise HTTPException(
            status_code=409,
            detail="LLM draft only available once the report has finished processing.",
        )
    if not row.get("original_object_key"):
        raise HTTPException(status_code=400, detail="Report has no original image key.")

    try:
        job = enqueue_llm_draft(report_id, physician_id)
    except Exception as e:
        raise HTTPException(
            status_code=503,
            detail=f"Could not enqueue LLM job (Redis unavailable): {e!s}",
        ) from e

    response.status_code = 202
    return {
        "ok": True,
        "report_id": report_id,
        "job": job,
    }

"""Stream PHI blobs from R2; requires X-Internal-Token (PHP server-side only)."""

from __future__ import annotations

import io
import os
from typing import Any

import cv2 as cv
import numpy as np
from fastapi import APIRouter, Depends, HTTPException, Query, Request, Response
from PIL import Image

from src import persistence, storage_r2
from src.landmark_recompute import recompute_from_flat_landmarks
from src.llm_jobs import enqueue_llm_draft, get_active_job_state
from src.render_computed import render_computed_overlay
from src.report_derived import build_report_derived

router = APIRouter(tags=["internal"])


def require_internal_file_token(request: Request) -> None:
    """If INFERENCE_INTERNAL_TOKEN is set, require the same value in X-Internal-Token."""
    expected = os.environ.get("INFERENCE_INTERNAL_TOKEN", "").strip()
    if not expected:
        return
    got = (request.headers.get("X-Internal-Token") or "").strip()
    if got != expected:
        raise HTTPException(status_code=401, detail="Invalid or missing X-Internal-Token.")


@router.get("/internal/reports/{report_id}/file", dependencies=[Depends(require_internal_file_token)])
async def internal_report_file(
    report_id: int,
    kind: str = Query("computed", description="original or computed"),
) -> Response:
    if not storage_r2.r2_configured():
        raise HTTPException(status_code=503, detail="R2 is not configured.")
    k = kind.lower().strip()
    if k not in ("original", "computed"):
        raise HTTPException(status_code=400, detail="kind must be original or computed.")

    row = persistence.fetch_report(report_id)
    if not row:
        raise HTTPException(status_code=404, detail="Report not found.")
    if k == "original":
        okey = row.get("original_object_key")
    else:
        if (row.get("status") or "") != "completed":
            raise HTTPException(status_code=404, detail="Computed image not available yet.")
        okey = row.get("computed_object_key")
    if not okey:
        raise HTTPException(status_code=404, detail="Object key not set.")
    data = storage_r2.get_bytes(str(okey))
    return Response(content=data, media_type="image/jpeg")


@router.get(
    "/internal/pdf-reports/{pdf_id}/file", dependencies=[Depends(require_internal_file_token)]
)
async def internal_pdf_file(pdf_id: int) -> Response:
    if not storage_r2.r2_configured():
        raise HTTPException(status_code=503, detail="R2 is not configured.")
    row = persistence.fetch_pdf_report(pdf_id)
    if not row:
        raise HTTPException(status_code=404, detail="PDF not found.")
    okey = row.get("pdf_object_key")
    if not okey:
        raise HTTPException(status_code=404, detail="Missing object key.")
    data = storage_r2.get_bytes(str(okey))
    headers = {"Content-Disposition": f'inline; filename="scoliosoft-pdf-{pdf_id}.pdf"'}
    return Response(content=data, media_type="application/pdf", headers=headers)


def _parse_flat_landmarks(raw: Any) -> list[float] | None:
    if raw is None:
        return None
    if isinstance(raw, list) and len(raw) >= 144 and all(
        isinstance(x, (int, float)) for x in raw
    ):
        return [float(x) for x in raw]
    return None


@router.post(
    "/internal/reports/{report_id}/recompute-landmarks",
    dependencies=[Depends(require_internal_file_token)],
)
async def internal_recompute_landmarks(
    report_id: int,
    request: Request,
) -> dict[str, Any]:
    if not storage_r2.r2_configured():
        raise HTTPException(status_code=503, detail="R2 is not configured.")
    try:
        body: dict[str, Any] = await request.json()
    except Exception as e:
        raise HTTPException(status_code=400, detail="Invalid JSON body.") from e
    flat = _parse_flat_landmarks(body.get("landmarks"))
    if not flat:
        raise HTTPException(
            status_code=400,
            detail="Body must include 'landmarks': a JSON array of at least 144 numbers (flat x,y list).",
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
        raw = storage_r2.get_bytes(str(okey))
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
    computed_key = storage_r2.object_key_computed(pid, report_id)
    try:
        storage_r2.put_bytes(computed_key, jpeg_bytes, content_type="image/jpeg")
    except Exception as e:
        raise HTTPException(
            status_code=502, detail=f"Could not write computed image: {e!s}"
        ) from e

    try:
        persistence.insert_landmark_revision(
            report_id, flat, physician_id, "manual_json"
        )
    except Exception:
        pass

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


@router.get(
    "/internal/reports/{report_id}/llm-impression",
    dependencies=[Depends(require_internal_file_token)],
)
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


@router.post(
    "/internal/reports/{report_id}/llm-impression",
    dependencies=[Depends(require_internal_file_token)],
)
async def internal_create_llm_impression(
    report_id: int,
    request: Request,
    response: Response,
) -> dict[str, Any]:
    if not storage_r2.r2_configured():
        raise HTTPException(status_code=503, detail="R2 is not configured.")

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

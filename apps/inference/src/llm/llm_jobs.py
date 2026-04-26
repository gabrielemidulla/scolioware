from __future__ import annotations

import os
from datetime import date, datetime
from typing import Any

import structlog

from src.db import persistence
from src.llm.llm_client import LlmError
from src.llm.llm_client import chat as llm_chat
from src.llm.llm_client import default_model as llm_default_model
from src.llm.llm_prompts import (
    PROMPT_VERSION,
    build_xray_description_messages,
    downscale_for_vision,
)
from src.storage import dashboard_storage

log = structlog.get_logger("llm_jobs")

LLM_QUEUE = "sw_llm"
_ACTIVE_KEY_FMT = "sw:llm_draft_active:{rid}"
_ACTIVE_KEY_TTL = 1800
_JOB_TIMEOUT_S = 900
_RESULT_TTL_S = 3600
_FAILURE_TTL_S = 3600


def _redis():
    from redis import Redis

    return Redis.from_url(
        (os.environ.get("REDIS_URL") or "redis://127.0.0.1:6379/0").strip(),
        decode_responses=False,
        socket_connect_timeout=3,
        socket_timeout=5,
    )


def _queue():
    from rq import Queue

    return Queue(LLM_QUEUE, connection=_redis())


def _active_key(report_id: int) -> str:
    return _ACTIVE_KEY_FMT.format(rid=int(report_id))


def _job_to_dict(job, status: str | None = None) -> dict[str, Any]:
    st = status or job.get_status(refresh=False)
    err: str | None = None
    if st == "failed":
        raw = job.exc_info or ""
        if isinstance(raw, bytes):
            raw = raw.decode("utf-8", errors="replace")
        tail = next(
            (ln for ln in reversed(str(raw).strip().splitlines()) if ln.strip()),
            "LLM job failed",
        )
        err = tail.strip()[:300]
    enq = job.enqueued_at.isoformat() if getattr(job, "enqueued_at", None) else None
    return {"id": job.id, "status": st, "enqueued_at": enq, "error": err}


def enqueue_llm_draft(report_id: int, physician_id: int | None) -> dict[str, Any]:
    from rq.exceptions import NoSuchJobError
    from rq.job import Job

    r = _redis()
    key = _active_key(report_id)
    existing = r.get(key)
    if existing:
        try:
            j = Job.fetch(existing.decode("utf-8"), connection=r)
            st = j.get_status(refresh=True)
            if st in ("queued", "started", "deferred", "scheduled"):
                return _job_to_dict(j, st)
        except NoSuchJobError:
            pass

    job = _queue().enqueue(
        run_llm_draft_job,
        int(report_id),
        int(physician_id) if physician_id else None,
        job_timeout=_JOB_TIMEOUT_S,
        result_ttl=_RESULT_TTL_S,
        failure_ttl=_FAILURE_TTL_S,
    )
    try:
        r.set(key, job.id, ex=_ACTIVE_KEY_TTL)
    except Exception as e:
        log.warning("llm_jobs_active_key_set_failed", report_id=report_id, error=str(e))
    return _job_to_dict(job)


def get_active_job_state(report_id: int) -> dict[str, Any] | None:
    from rq.exceptions import NoSuchJobError
    from rq.job import Job

    r = _redis()
    raw = r.get(_active_key(report_id))
    if not raw:
        return None
    try:
        j = Job.fetch(raw.decode("utf-8"), connection=r)
    except NoSuchJobError:
        return None
    return _job_to_dict(j, j.get_status(refresh=True))


def _age_years_from_birthdate(value: Any) -> int | None:
    if value is None:
        return None
    try:
        if isinstance(value, datetime):
            bd = value.date()
        elif isinstance(value, date):
            bd = value
        else:
            bd = datetime.strptime(str(value)[:10], "%Y-%m-%d").date()
    except (ValueError, TypeError):
        return None
    today = date.today()
    yrs = today.year - bd.year - ((today.month, today.day) < (bd.month, bd.day))
    if 0 <= yrs <= 120:
        return int(yrs)
    return None


def _patient_meta(patient_id: int) -> tuple[int | None, str | None]:
    row = persistence.fetch_patient_meta(patient_id)
    if not row:
        return None, None
    return _age_years_from_birthdate(row.get("birth_date")), (
        str(row.get("gender")) if row.get("gender") else None
    )


def run_llm_draft_job(report_id: int, physician_id: int | None) -> dict[str, Any]:
    rid = int(report_id)
    log.info("llm_job_start", report_id=rid, physician_id=physician_id)

    if not dashboard_storage.storage_proxy_configured():
        raise RuntimeError("Dashboard storage proxy is not configured (DASHBOARD_STORAGE_URL).")

    row = persistence.fetch_report(rid)
    if not row:
        raise RuntimeError(f"Report {rid} not found.")
    if (row.get("status") or "") != "completed":
        raise RuntimeError(
            "LLM draft only available once the report has finished processing."
        )
    okey = row.get("original_object_key")
    if not okey:
        raise RuntimeError("Report has no original image key.")

    raw = dashboard_storage.get_bytes(str(okey))
    image_for_llm = downscale_for_vision(raw)

    age, sex = _patient_meta(int(row["patient_id"]))
    curve = row.get("curve_type")
    curve_str = str(curve) if isinstance(curve, str) and curve else None
    messages = build_xray_description_messages(
        patient_age_years=age,
        patient_sex=sex,
        curve_type=curve_str,
    )

    try:
        resp = llm_chat(messages=messages, image_bytes=image_for_llm)
    except LlmError as e:
        log.error("llm_job_call_failed", report_id=rid, error=str(e))
        raise RuntimeError(f"LLM call failed: {e!s}") from e

    draft_id = persistence.insert_llm_draft(
        report_id=rid,
        physician_id=physician_id,
        model_tag=resp.model_tag or llm_default_model(),
        prompt_version=PROMPT_VERSION,
        response_text=resp.text,
        latency_ms=resp.latency_ms,
        prompt_tokens=resp.prompt_tokens,
        completion_tokens=resp.completion_tokens,
    )
    log.info(
        "llm_job_done",
        report_id=rid,
        draft_id=draft_id,
        latency_ms=resp.latency_ms,
        model_tag=resp.model_tag,
    )
    return {
        "draft_id": draft_id,
        "model_tag": resp.model_tag,
        "latency_ms": resp.latency_ms,
    }

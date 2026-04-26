"""Enqueue `process_report_by_id` on RQ queue `sv_reports`."""

from __future__ import annotations

import os

_REDIS_URL = (os.environ.get("REDIS_URL") or "redis://127.0.0.1:6379/0").strip()
_QUEUE = "sv_reports"


def _queue():
    from redis import Redis
    from rq import Queue

    return Queue(
        _QUEUE,
        connection=Redis.from_url(
            _REDIS_URL,
            decode_responses=False,
            socket_connect_timeout=3,
            socket_timeout=5,
        ),
    )


def enqueue_process_report(report_id: int) -> None:
    """Raises if Redis is unreachable."""
    from src.worker import process_report_by_id

    _queue().enqueue(process_report_by_id, int(report_id), job_timeout=900)


def ping() -> bool:
    try:
        from redis import Redis

        Redis.from_url(
            _REDIS_URL,
            decode_responses=False,
            socket_connect_timeout=2,
            socket_timeout=2,
        ).ping()
        return True
    except Exception:
        return False

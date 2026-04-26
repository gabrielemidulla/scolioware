from __future__ import annotations

import os
import time

import structlog

log = structlog.get_logger("worker_main")


def _warm_model() -> None:
    t0 = time.monotonic()
    from src.vision.get_model import ensure_model_weights

    ensure_model_weights()
    from src.vision.spine_net_infer import _get_model_cached, _infer_device

    model = _get_model_cached()
    device = _infer_device()
    model.to(device)
    model.eval()
    log.info(
        "model_warm_ready",
        device=str(device),
        warm_seconds=round(time.monotonic() - t0, 2),
    )


def main() -> None:
    log.info("worker_starting", queue="sw_reports")
    _warm_model()

    from redis import Redis
    from rq import Queue, SimpleWorker

    redis_url = (os.environ.get("REDIS_URL") or "redis://127.0.0.1:6379/0").strip()
    conn = Redis.from_url(redis_url, decode_responses=False)
    queue = Queue("sw_reports", connection=conn)
    log.info("worker_listening", queue=queue.name, redis=redis_url)
    SimpleWorker([queue], connection=conn).work(with_scheduler=False)


if __name__ == "__main__":
    main()

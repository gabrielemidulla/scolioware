"""Long-lived RQ worker dedicated to LLM draft jobs (Ollama calls).

Separate from the keypoint-RCNN worker (`worker_main.py`) so that scan
processing is not blocked while a draft is generating, which on CPU can
take 30–120 s per call. This worker:

* listens only on the `sv_llm` queue,
* skips the torch/keypoint-RCNN warm step,
* uses `rq.SimpleWorker` (no fork-per-job) so a long-lived `requests`
  session and any module-level Ollama caches survive across jobs.

Boot order:
  1. Connect to Redis.
  2. Start `SimpleWorker` on the `sv_llm` queue and block.
"""

from __future__ import annotations

import os

import structlog

log = structlog.get_logger("worker_llm_main")


def main() -> None:
    log.info("worker_llm_starting", queue="sv_llm")

    from redis import Redis
    from rq import Queue, SimpleWorker

    redis_url = (os.environ.get("REDIS_URL") or "redis://127.0.0.1:6379/0").strip()
    conn = Redis.from_url(redis_url, decode_responses=False)
    queue = Queue("sv_llm", connection=conn)
    log.info("worker_llm_listening", queue=queue.name, redis=redis_url)
    SimpleWorker([queue], connection=conn).work(with_scheduler=False)


if __name__ == "__main__":
    main()

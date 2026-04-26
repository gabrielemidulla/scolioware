from __future__ import annotations

import os

import structlog

log = structlog.get_logger("worker_llm_main")


def main() -> None:
    log.info("worker_llm_starting", queue="sw_llm")

    from redis import Redis
    from rq import Queue, SimpleWorker

    redis_url = (os.environ.get("REDIS_URL") or "redis://127.0.0.1:6379/0").strip()
    conn = Redis.from_url(redis_url, decode_responses=False)
    queue = Queue("sw_llm", connection=conn)
    log.info("worker_llm_listening", queue=queue.name, redis=redis_url)
    SimpleWorker([queue], connection=conn).work(with_scheduler=False)


if __name__ == "__main__":
    main()

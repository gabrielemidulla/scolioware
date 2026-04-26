import os

import src.env_bootstrap  # noqa: F401  (side effect: dotenv)

from contextlib import asynccontextmanager
from io import BytesIO

import cv2 as cv
import numpy as np
import structlog
from fastapi import FastAPI, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from PIL import Image

from src.api.internal import router as internal_router
from src.api.reports import router as reports_router
from src.vision.spine_net_infer import build_inference_api, predict


@asynccontextmanager
async def lifespan(app: FastAPI):
    structlog.configure(
        processors=[
            structlog.processors.add_log_level,
            structlog.processors.TimeStamper(fmt="iso"),
            structlog.dev.ConsoleRenderer()
            if (os.environ.get("STRUCTLOG_JSON") or "").strip().lower()
            not in ("1", "true", "yes")
            else structlog.processors.JSONRenderer(),
        ]
    )
    log = structlog.get_logger("inference")

    paths = sorted(
        {getattr(r, "path", "") for r in app.routes if getattr(r, "path", None)}
    )
    log.info("http_routes", path_list=", ".join(paths))
    log.info("queue_mode", backend="rq", queues="sw_reports,sw_llm")
    yield


app = FastAPI(lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None)

_cors = [
    o.strip()
    for o in os.environ.get("CORS_ALLOW_ORIGIN", "http://localhost:8080,http://127.0.0.1:8080").split(",")
    if o.strip()
]
if _cors:
    app.add_middleware(
        CORSMiddleware,
        allow_origins=_cors,
        allow_credentials=True,
        allow_methods=["*"],
        allow_headers=["*"],
    )


app.include_router(reports_router)
app.include_router(internal_router)


@app.get("/")
async def read_root():
    return {
        "Hello": "World",
        "Message": "Scolioware inference (internal + dashboard-proxied only).",
        "Health": "GET /healthz",
    }


@app.get("/healthz")
async def healthz() -> dict[str, str]:
    from src.queue.rq_tasks import ping as redis_ping
    from src.storage import dashboard_storage

    return {
        "status": "ok",
        "redis": "ok" if redis_ping() else "down",
        "dashboard_storage": "ok" if dashboard_storage.storage_proxy_configured() else "missing",
    }


@app.post("/v2/getprediction")
async def get_prediction_v2(image: UploadFile):
    image = Image.open(BytesIO(await image.read())).convert("RGB")
    image = cv.cvtColor(np.array(image), cv.COLOR_RGB2BGR)
    bboxes, keypoints, scores = predict(image)[0]
    return build_inference_api(bboxes, keypoints, scores, image.shape)

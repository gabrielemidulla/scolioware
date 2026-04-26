import os
from pathlib import Path

import dotenv

_here = Path(__file__).resolve().parent
_dotenv_candidates = [_here / ".env"]
if _here.name == "inference" and _here.parent.name == "apps":
    _repo = _here.parent.parent
    _dotenv_candidates.extend(
        [
            _repo / "infra" / ".env",
            _repo / ".env",
        ]
    )
for _p in _dotenv_candidates:
    if _p.is_file():
        dotenv.load_dotenv(_p)
dotenv.load_dotenv()
from contextlib import asynccontextmanager
from io import BytesIO

import cv2 as cv
import numpy as np
import structlog
from fastapi import FastAPI, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from PIL import Image
from src.api_internal import router as internal_router
from src.api_pdf_reports import router as pdf_reports_router
from src.api_reports import router as reports_router


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
    log.info("queue_mode", backend="rq", queues="sv_reports,sv_llm")
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
app.include_router(pdf_reports_router)
app.include_router(internal_router)

from src.kprcnn import kprcnn_to_api_format, predict


@app.get("/")
async def read_root():
    return {
        "Hello": "World",
        "Message": "Scoliosoft inference (internal + dashboard-proxied only).",
        "Health": "GET /healthz",
    }


@app.get("/healthz")
async def healthz() -> dict[str, str]:
    from src import storage_r2
    from src.rq_tasks import ping as redis_ping

    return {
        "status": "ok",
        "redis": "ok" if redis_ping() else "down",
        "r2": "ok" if storage_r2.r2_configured() else "missing",
    }


@app.post("/v2/getprediction")
async def get_prediction_v2(image: UploadFile):
    image = Image.open(BytesIO(await image.read())).convert("RGB")
    image = cv.cvtColor(np.array(image), cv.COLOR_RGB2BGR)
    bboxes, keypoints, scores = predict(image)[0]
    return kprcnn_to_api_format(bboxes, keypoints, scores, image.shape)

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

from fastapi import FastAPI, UploadFile
from fastapi.middleware.cors import CORSMiddleware


@asynccontextmanager
async def lifespan(app: FastAPI):
    from src.worker import start_worker

    paths = sorted(
        {getattr(r, "path", "") for r in app.routes if getattr(r, "path", None)}
    )
    print("inference: registered HTTP paths:", ", ".join(paths), flush=True)

    stop = start_worker()
    yield
    stop.set()


app = FastAPI(lifespan=lifespan)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

from src.api_reports import router as reports_router
from src.api_pdf_reports import router as pdf_reports_router

app.include_router(reports_router)
app.include_router(pdf_reports_router)

from io import BytesIO

import cv2 as cv
import numpy as np
from PIL import Image

from src.kprcnn import predict, kprcnn_to_api_format


@app.get("/")
async def read_root():
    print("Read Root started")
    return {
        "Hello": "World",
        "Message": "Welcome to Scoliosoft-API! Send a POST request these APIs to get started!",
        "ModelPredict": "/v2/getprediction",
        "EnqueueScan": "POST /enqueue or POST /v2/enqueue (multipart: patient_id, image; optional height_cm, weight_kg)",
        "ReportStatus": "GET /reports/{report_id}",
    }


@app.post("/v2/getprediction")
async def get_prediction_v2(image: UploadFile):
    image = Image.open(BytesIO(await image.read())).convert("RGB")
    image = cv.cvtColor(np.array(image), cv.COLOR_RGB2BGR)
    bboxes, keypoints, scores = predict(image)[0]
    return kprcnn_to_api_format(bboxes, keypoints, scores, image.shape)
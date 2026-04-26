"""SpineNet vertebra detection and inference API payload assembly."""

from __future__ import annotations

import os

import cv2 as cv
import numpy as np
import torch

from src.spine_net import DecDecoder
from src.vision.cobb_angle_cal import cobb_angle_cal, keypoints_to_landmark_xy
from src.vision.get_model import (
    DOWN_RATIO,
    INPUT_H,
    INPUT_W,
    NUM_VERTEBRAE,
    get_spine_net_model,
)

DEFAULT_CONF_THRESH = 0.20
MIN_VERTEBRAE_AFTER_FILTER = 2
SPINE_GAP_OUTLIER_RATIO = 2.0
SPINE_MIN_VERTS_FOR_GAP_FILTER = 6
SPINE_INTERIOR_GAP_FATAL_RATIO = 2.5

_model = None
_decoder: DecDecoder | None = None


def _conf_threshold() -> float:
    raw = os.environ.get("SPINE_NET_CONF_THRESH")
    if raw is None or not str(raw).strip():
        return DEFAULT_CONF_THRESH
    try:
        v = float(raw)
    except ValueError:
        return DEFAULT_CONF_THRESH
    if v < 0.0:
        return 0.0
    if v > 1.0:
        return 1.0
    return v


def _get_model_cached():
    global _model
    if _model is None:
        _model = get_spine_net_model()
    return _model


def _get_decoder_cached() -> DecDecoder:
    global _decoder
    if _decoder is None:
        _decoder = DecDecoder(K=NUM_VERTEBRAE, conf_thresh=_conf_threshold())
    return _decoder


def _infer_device() -> torch.device:
    s = (os.environ.get("SPINE_NET_DEVICE") or os.environ.get("TORCH_DEVICE") or "cpu").strip()
    if s.lower() == "cuda" and torch.cuda.is_available():
        return torch.device("cuda")
    if s.lower().startswith("cuda:") and torch.cuda.is_available():
        return torch.device(s)
    return torch.device("cpu")


def _preprocess(image_bgr: np.ndarray) -> torch.Tensor:
    resized = cv.resize(image_bgr, (INPUT_W, INPUT_H))
    x = resized.astype(np.float32) / 255.0 - 0.5
    x = x.transpose(2, 0, 1).reshape(1, 3, INPUT_H, INPUT_W)
    return torch.from_numpy(x)


def _rearrange_per_vertebra(corners4: np.ndarray) -> np.ndarray:
    pts = np.asarray(corners4, dtype=np.float32).reshape(4, 2)
    x_inds = np.argsort(pts[:, 0])
    pt_l = pts[x_inds[:2]]
    pt_r = pts[x_inds[2:]]
    yl = np.argsort(pt_l[:, 1])
    yr = np.argsort(pt_r[:, 1])
    tl = pt_l[yl[0]]
    bl = pt_l[yl[1]]
    tr = pt_r[yr[0]]
    br = pt_r[yr[1]]
    return np.stack([tl, tr, bl, br], axis=0)


def _drop_endpoint_gap_outliers(out: np.ndarray) -> np.ndarray:
    while out.shape[0] > MIN_VERTEBRAE_AFTER_FILTER and out.shape[0] >= SPINE_MIN_VERTS_FOR_GAP_FILTER:
        ys = out[:, 1]
        gaps = np.diff(ys)
        if gaps.size < 3:
            break
        interior = np.sort(gaps)[:-1]
        med = float(np.median(interior))
        if med <= 0.0:
            break
        threshold = SPINE_GAP_OUTLIER_RATIO * med
        if gaps[-1] > threshold:
            out = out[:-1]
            continue
        if gaps[0] > threshold:
            out = out[1:]
            continue
        break
    return out


def _decoded_to_api(
    pts: np.ndarray, image_shape: tuple[int, int, int]
) -> tuple[list[list[int]], list[list[list[float]]], list[float]]:
    if pts.size == 0:
        return [], [], []

    h, w = image_shape[:2]
    out = pts.copy()
    out[:, :10] *= float(DOWN_RATIO)
    x_idx = np.arange(0, 10, 2)
    y_idx = np.arange(1, 10, 2)
    out[:, x_idx] = out[:, x_idx] / float(INPUT_W) * float(w)
    out[:, y_idx] = out[:, y_idx] / float(INPUT_H) * float(h)

    thresh = _conf_threshold()
    raw_scores = out[:, 10]
    keep = raw_scores >= thresh
    if int(keep.sum()) < MIN_VERTEBRAE_AFTER_FILTER:
        topn = max(MIN_VERTEBRAE_AFTER_FILTER, int(keep.sum()))
        order = np.argsort(-raw_scores)
        keep = np.zeros_like(keep)
        keep[order[:topn]] = True
    out = out[keep]

    if out.size == 0:
        return [], [], []

    out = out[np.argsort(out[:, 1])]
    out = _drop_endpoint_gap_outliers(out)

    bboxes: list[list[int]] = []
    keypoints: list[list[list[float]]] = []
    scores: list[float] = []
    for row in out:
        score = float(row[10])
        corners_raw = row[2:10].reshape(4, 2)
        corners = _rearrange_per_vertebra(corners_raw)
        xs = corners[:, 0]
        ys = corners[:, 1]
        margin = 4.0
        bboxes.append([
            int(np.floor(float(xs.min()) - margin)),
            int(np.floor(float(ys.min()) - margin)),
            int(np.ceil(float(xs.max()) + margin)),
            int(np.ceil(float(ys.max()) + margin)),
        ])
        keypoints.append([[float(c[0]), float(c[1])] for c in corners])
        scores.append(score)
    return bboxes, keypoints, scores


def predict(
    images: np.ndarray,
) -> list[tuple[list[list[int]], list[list[list[float]]], list[float]]]:
    if isinstance(images, np.ndarray) and images.ndim == 3:
        batch = [images]
    elif isinstance(images, (list, tuple)):
        batch = list(images)
    else:
        raise TypeError(
            "predict() expects a single H×W×3 BGR image or a list of them; "
            f"got {type(images).__name__}."
        )

    device = _infer_device()
    model = _get_model_cached()
    model.to(device)
    model.eval()
    decoder = _get_decoder_cached()

    results = []
    with torch.no_grad():
        for img in batch:
            x = _preprocess(img).to(device)
            output = model(x)
            pts = decoder.ctdet_decode(output["hm"], output["wh"], output["reg"])
            results.append(_decoded_to_api(pts, img.shape))
    return results


def _chain_discontinuity(bboxes: list[list[int]]) -> tuple[float, float] | None:
    if len(bboxes) < SPINE_MIN_VERTS_FOR_GAP_FILTER:
        return None
    cys = np.array([(float(b[1]) + float(b[3])) / 2.0 for b in bboxes], dtype=np.float64)
    gaps = np.diff(cys)
    if gaps.size < 3:
        return None
    med = float(np.median(gaps))
    if med <= 0.0:
        return None
    mx = float(gaps.max())
    if mx > SPINE_INTERIOR_GAP_FATAL_RATIO * med:
        return mx, med
    return None


def build_inference_api(
    bboxes: list,
    keypoints: list,
    scores: list,
    image_shape: tuple[int, ...],
) -> dict:
    if not bboxes or not keypoints:
        return {
            "detections": [],
            "landmarks": [],
            "angles": None,
            "curve_type": None,
            "midpoint_lines": None,
            "cobb_error": "No vertebrae detected.",
        }

    detections = []
    for idx, bbox in enumerate(bboxes):
        detections.append(
            {
                "class": 0,
                "confidence": float(scores[idx]) if idx < len(scores) else 0.0,
                "name": "vert",
                "xmin": int(bbox[0]),
                "ymin": int(bbox[1]),
                "xmax": int(bbox[2]),
                "ymax": int(bbox[3]),
            }
        )

    landmarks: list[float] = []
    for kps in keypoints:
        for kp in kps:
            landmarks.append(float(kp[0]))
            landmarks.append(float(kp[1]))

    curve_type = None
    angles = None
    midpoint_lines = None
    cobb_error: str | None = None
    discontinuity = _chain_discontinuity(bboxes)
    if discontinuity is not None:
        mx, med = discontinuity
        cobb_error = (
            "Spine detection has a large interior gap "
            f"({mx:.0f}px vs median {med:.0f}px between consecutive vertebrae). "
            "Mid-thoracic vertebrae appear undetected; Cobb angles would be unreliable. "
            "Try a higher-quality, tightly-cropped X-ray."
        )
    else:
        try:
            _, angles, curve_type, midpoint_lines = cobb_angle_cal(
                keypoints_to_landmark_xy(keypoints), image_shape
            )
        except Exception as e:
            curve_type = None
            angles = None
            midpoint_lines = None
            cobb_error = f"Cobb angle failed: {e!s}"

    return {
        "detections": detections,
        "landmarks": landmarks,
        "angles": angles,
        "curve_type": curve_type,
        "midpoint_lines": midpoint_lines,
        **({"cobb_error": cobb_error} if cobb_error is not None else {}),
    }

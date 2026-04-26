"""Recompute Cobb / overlay from manual flat landmarks (18 vertebrae x 4 keypoints x 2)."""

from __future__ import annotations

from typing import Any

import numpy as np

from src.kprcnn import kprcnn_to_api_format


def flat_to_keypoints(flat: list[float] | list[int], n_verts: int = 18, n_kp: int = 4) -> list[list[list[float]]]:
    """Flat [x0,y0,...] → list of `n_verts` lists of `n_kp` [x,y] pairs."""
    if len(flat) < n_verts * n_kp * 2:
        raise ValueError(
            f"Need at least {n_verts * n_kp * 2} coordinate values, got {len(flat)}."
        )
    out: list[list[list[float]]] = []
    for v in range(n_verts):
        kps: list[list[float]] = []
        for k in range(n_kp):
            i = 2 * (v * n_kp + k)
            kps.append([float(flat[i]), float(flat[i + 1])])
        out.append(kps)
    return out


def _detections_from_keypoints(
    keypoints: list[list[list[float]]], confidence: float = 1.0
) -> list[dict[str, Any]]:
    out = []
    for _idx, kps in enumerate(keypoints):
        xs = [p[0] for p in kps]
        ys = [p[1] for p in kps]
        m = 8.0
        out.append(
            {
                "class": 0,
                "confidence": confidence,
                "name": "vert",
                "xmin": int(min(xs) - m),
                "ymin": int(min(ys) - m),
                "xmax": int(max(xs) + m),
                "ymax": int(max(ys) + m),
            }
        )
    return out


def build_api_from_keypoints(
    keypoints: list[list[list[float]]], image_shape: tuple[int, ...]
) -> dict[str, Any]:
    """Cobb + flat landmarks + optional synthetic boxes for drawing."""
    scores = [1.0] * len(keypoints)
    bboxes = [
        (d["xmin"], d["ymin"], d["xmax"], d["ymax"])
        for d in _detections_from_keypoints(keypoints)
    ]
    return kprcnn_to_api_format(bboxes, keypoints, scores, image_shape)


def recompute_from_flat_landmarks(
    flat: list[float], image_bgr: np.ndarray
) -> tuple[dict[str, Any], str | None]:
    """Return (api dict, error message or None if Cobb failed hard)."""
    kps = flat_to_keypoints(flat)
    api = build_api_from_keypoints(kps, image_bgr.shape)
    if api.get("cobb_error") is not None or api.get("angles") is None:
        return (
            api,
            str(api.get("cobb_error") or "Cobb could not be computed for these points."),
        )
    return (api, None)

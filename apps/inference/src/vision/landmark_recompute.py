"""Cobb and overlay from flat landmark coordinates."""

from __future__ import annotations

from typing import Any

import numpy as np

from src.vision.spine_net_infer import build_inference_api

DEFAULT_N_KP = 4
MIN_VERTS = 2


def flat_to_keypoints(
    flat: list[float] | list[int],
    n_verts: int | None = None,
    n_kp: int = DEFAULT_N_KP,
) -> list[list[list[float]]]:
    if n_kp <= 0:
        raise ValueError("n_kp must be positive.")
    per_vert = n_kp * 2
    inferred = len(flat) // per_vert
    if n_verts is None:
        n_verts = inferred
    if n_verts < MIN_VERTS:
        raise ValueError(
            f"Need landmarks for at least {MIN_VERTS} vertebrae "
            f"({MIN_VERTS * per_vert} numbers); got {len(flat)}."
        )
    if len(flat) < n_verts * per_vert:
        raise ValueError(
            f"Need at least {n_verts * per_vert} coordinate values, got {len(flat)}."
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
    scores = [1.0] * len(keypoints)
    bboxes = [
        (d["xmin"], d["ymin"], d["xmax"], d["ymax"])
        for d in _detections_from_keypoints(keypoints)
    ]
    return build_inference_api(bboxes, keypoints, scores, image_shape)


def recompute_from_flat_landmarks(
    flat: list[float], image_bgr: np.ndarray
) -> tuple[dict[str, Any], str | None]:
    kps = flat_to_keypoints(flat)
    api = build_api_from_keypoints(kps, image_bgr.shape)
    if api.get("cobb_error") is not None or api.get("angles") is None:
        return (
            api,
            str(api.get("cobb_error") or "Cobb could not be computed for these points."),
        )
    return (api, None)

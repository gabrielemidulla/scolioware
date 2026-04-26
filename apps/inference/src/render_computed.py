"""Render model output (detections, landmarks, Cobb guides) onto a BGR image."""

from __future__ import annotations

from typing import Any

import cv2 as cv
import numpy as np


def _create_long_line(
    x1: float, x2: float, y1: float, y2: float, image_width: int, image_height: int
) -> tuple[tuple[int, int], tuple[int, int]]:
    if abs(x2 - x1) < 1e-6:
        return (int(x1), 0), (int(x1), int(image_height * 2))
    m = (y2 - y1) / (x2 - x1)
    left_x = -10
    right_x = image_width + 10
    left_y = -1 * (m * (x2 - left_x) - y2)
    right_y = m * (right_x - x1) + y1
    return (int(left_x), int(left_y)), (int(right_x), int(right_y))


def _landmark_points(landmarks_flat: list) -> list[list[float]]:
    pts: list[list[float]] = []
    for i in range(0, len(landmarks_flat), 2):
        if i + 1 < len(landmarks_flat):
            pts.append([float(landmarks_flat[i]), float(landmarks_flat[i + 1])])
    return pts


def _paths_all_lines(points: list[list[float]]) -> list[list[list[float]]]:
    paths: list[list[list[float]]] = []
    step = 4
    for i in range(0, len(points), step):
        if i + 3 >= len(points):
            break
        paths.append(
            [
                points[i],
                points[i + 1],
                points[i + 3],
                points[i + 2],
                points[i],
            ]
        )
    return paths


def render_computed_overlay(
    image_bgr: np.ndarray,
    api: dict[str, Any],
) -> bytes:
    """Return JPEG bytes (BGR input)."""
    out = image_bgr.copy()
    h, w = out.shape[:2]

    detections = api.get("detections") or []
    for det in detections:
        x0, y0, x1, y1 = int(det["xmin"]), int(det["ymin"]), int(det["xmax"]), int(det["ymax"])
        cv.rectangle(out, (x0, y0), (x1, y1), (255, 0, 0), 2)

    lm = api.get("landmarks") or []
    points = _landmark_points(lm)
    for path in _paths_all_lines(points):
        for i in range(len(path) - 1):
            p0 = (int(path[i][0]), int(path[i][1]))
            p1 = (int(path[i + 1][0]), int(path[i + 1][1]))
            cv.line(out, p0, p1, (255, 255, 0), 2)

    for i in range(0, len(points)):
        c = (0, 200, 255) if i % 4 in (0, 1) else (0, 128, 255)
        cv.circle(out, (int(points[i][0]), int(points[i][1])), 4, c, -1)

    midpoint_lines = api.get("midpoint_lines")
    if midpoint_lines:
        for seg in midpoint_lines:
            if len(seg) >= 2:
                a = (int(seg[0][0]), int(seg[0][1]))
                b = (int(seg[1][0]), int(seg[1][1]))
                cv.line(out, a, b, (255, 255, 255), 3)

    angles = api.get("angles")
    if angles and midpoint_lines and len(midpoint_lines) > 0:
        bgr = {"pt": (0, 140, 255), "tl": (0, 255, 0), "mt": (255, 0, 255)}
        for name, color in bgr.items():
            block = angles.get(name)
            if not block:
                continue
            idxs = block.get("idxs")
            if not idxs or len(idxs) < 2:
                continue
            top_i, bot_i = int(idxs[0]), int(idxs[1])
            if top_i >= len(midpoint_lines) or bot_i >= len(midpoint_lines):
                continue
            top = midpoint_lines[top_i]
            bot = midpoint_lines[bot_i]
            if len(top) < 2 or len(bot) < 2:
                continue
            t0, t1 = top[0], top[1]
            b0, b1 = bot[0], bot[1]
            p1a, p1b = _create_long_line(t0[0], t1[0], t0[1], t1[1], w, h)
            p2a, p2b = _create_long_line(b0[0], b1[0], b0[1], b1[1], w, h)
            cv.line(out, p1a, p1b, color, 3)
            cv.line(out, p2a, p2b, color, 3)
            ang = float(block.get("angle", 0))
            label = f"{name.upper()}={ang:.2f} deg"
            y_text = int((p1b[1] + p2b[1]) / 2)
            x_text = max(10, w - 420)
            cv.rectangle(out, (x_text - 5, y_text - 35), (x_text + 380, y_text + 15), (0, 0, 0), -1)
            cv.putText(
                out,
                label,
                (x_text, y_text),
                cv.FONT_HERSHEY_SIMPLEX,
                0.9,
                color,
                2,
                cv.LINE_AA,
            )

    curve = api.get("curve_type")
    if curve:
        cv.putText(
            out,
            f"Curve: {curve}",
            (10, 40),
            cv.FONT_HERSHEY_SIMPLEX,
            1.1,
            (255, 255, 255),
            2,
            cv.LINE_AA,
        )

    ok, buf = cv.imencode(".jpeg", out, [int(cv.IMWRITE_JPEG_QUALITY), 92])
    if not ok:
        raise RuntimeError("Failed to encode JPEG")
    return buf.tobytes()

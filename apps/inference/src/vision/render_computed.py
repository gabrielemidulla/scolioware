"""Render model output (detections, landmarks, Cobb guides) onto a BGR image."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

import cv2 as cv
import numpy as np

REF_MIN = 1080.0
T_BLEND_START = 3200.0
T_MAX = 10.0


@dataclass(frozen=True)
class _OverlayStyle:
    t: float
    box_line: int
    quad_line: int
    mid_line: int
    cobb_line: int
    dot_r: int
    font_label: float
    font_curve: float
    font_thick: int
    text_pad: int


def _overlay_style(w: int, h: int) -> _OverlayStyle:
    m = float(max(1, min(w, h)))
    t_lin = m / REF_MIN
    if m <= T_BLEND_START:
        t = t_lin
    else:
        t_at_blend = T_BLEND_START / REF_MIN
        extra = (m - T_BLEND_START) / REF_MIN
        t = t_at_blend + (extra**0.5) * 1.15
    t = max(0.32, min(t, T_MAX))
    return _OverlayStyle(
        t=t,
        box_line=max(1, round(1.45 * t)),
        quad_line=max(1, round(1.45 * t)),
        mid_line=max(1, round(1.65 * t)),
        cobb_line=max(1, round(2.0 * t)),
        dot_r=max(3, round(2.65 * t)),
        font_label=max(0.45, min(2.5, 0.52 * t)),
        font_curve=max(0.52, min(2.65, 0.58 * t)),
        font_thick=max(1, round(1.35 * t)),
        text_pad=max(4, round(6 * t)),
    )


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
    st = _overlay_style(w, h)
    margin = max(7, round(12 * st.t))

    detections = api.get("detections") or []
    for det in detections:
        x0, y0, x1, y1 = int(det["xmin"]), int(det["ymin"]), int(det["xmax"]), int(det["ymax"])
        cv.rectangle(out, (x0, y0), (x1, y1), (255, 0, 0), st.box_line)

    lm = api.get("landmarks") or []
    points = _landmark_points(lm)
    for path in _paths_all_lines(points):
        for i in range(len(path) - 1):
            p0 = (int(path[i][0]), int(path[i][1]))
            p1 = (int(path[i + 1][0]), int(path[i + 1][1]))
            cv.line(out, p0, p1, (255, 255, 0), st.quad_line)

    for i in range(0, len(points)):
        c = (0, 200, 255) if i % 4 in (0, 1) else (0, 128, 255)
        cv.circle(out, (int(points[i][0]), int(points[i][1])), st.dot_r, c, -1)

    midpoint_lines = api.get("midpoint_lines")
    if midpoint_lines:
        for seg in midpoint_lines:
            if len(seg) >= 2:
                a = (int(seg[0][0]), int(seg[0][1]))
                b = (int(seg[1][0]), int(seg[1][1]))
                cv.line(out, a, b, (255, 255, 255), st.mid_line)

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
            if top_i == bot_i:
                continue
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
            cv.line(out, p1a, p1b, color, st.cobb_line)
            cv.line(out, p2a, p2b, color, st.cobb_line)
            ang = float(block.get("angle", 0))
            label = f"{name.upper()}={ang:.2f} deg"
            y_anchor = int((p1b[1] + p2b[1]) / 2)
            (tw, th), bl = cv.getTextSize(
                label,
                cv.FONT_HERSHEY_SIMPLEX,
                st.font_label,
                st.font_thick,
            )
            x_text = max(margin, w - tw - st.text_pad * 2 - margin)
            y_baseline = int(
                np.clip(y_anchor, th + st.text_pad + 1, h - bl - st.text_pad - 1)
            )
            cv.rectangle(
                out,
                (x_text - st.text_pad, y_baseline - th - st.text_pad),
                (x_text + tw + st.text_pad, y_baseline + bl + st.text_pad),
                (0, 0, 0),
                -1,
            )
            cv.putText(
                out,
                label,
                (x_text, y_baseline),
                cv.FONT_HERSHEY_SIMPLEX,
                st.font_label,
                color,
                st.font_thick,
                cv.LINE_AA,
            )

    curve = api.get("curve_type")
    if curve:
        cy = margin + int(round(32 * st.t))
        cv.putText(
            out,
            f"Curve: {curve}",
            (margin, cy),
            cv.FONT_HERSHEY_SIMPLEX,
            st.font_curve,
            (255, 255, 255),
            st.font_thick,
            cv.LINE_AA,
        )

    ok, buf = cv.imencode(".jpeg", out, [int(cv.IMWRITE_JPEG_QUALITY), 92])
    if not ok:
        raise RuntimeError("Failed to encode JPEG")
    return buf.tobytes()

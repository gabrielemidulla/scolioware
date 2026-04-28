"""Standalone diagnostic for Cobb angle calculation.

Runs SpineNet on a single image, then prints the raw per-vertebra geometry
that ``cobb_angle_cal`` uses to decide segments and end vertebrae:

    idx | top_mid (x,y) | bot_mid (x,y) | body_tilt | upper_tilt | lower_tilt

It also prints the inflection indices, the resulting segments after the
sub-threshold merge step, the chosen end-vertebra pair per segment, and three
parallel Cobb estimates for the same pair so we can tell whether the limiting
factor is the predicted endplate orientations, the predicted body axis, or
the spinal centerline tangent (centroid finite-difference).

Usage:
    cd apps/inference
    python -m bin.cobb_debug /path/to/image.webp
    python -m bin.cobb_debug /path/to/image.webp --overlay /tmp/debug.jpg
"""

from __future__ import annotations

import argparse
import math
import sys
from pathlib import Path

import cv2 as cv
import numpy as np

import src.env_bootstrap  # noqa: F401  (side effect: dotenv)
from src.vision.cobb_angle_cal import (
    _MIN_SEGMENT_COBB_DEG,
    _max_endplate_pair,
    _merge_minor_segments,
    _segment_inflections,
    _signed_body_tilt,
    _vertebra_geometry,
    cobb_angle_cal,
    keypoints_to_landmark_xy,
)
from src.vision.spine_net_infer import build_inference_api, predict


def _tilt_from_vertical_deg(vec: np.ndarray) -> float:
    return math.degrees(math.atan2(float(vec[0]), float(vec[1])))


def _tilt_from_horizontal_deg(vec: np.ndarray) -> float:
    return math.degrees(math.atan2(float(vec[1]), float(vec[0])))


def _line_angle_unsigned(u: np.ndarray, v: np.ndarray) -> float:
    nu = float(np.linalg.norm(u))
    nv = float(np.linalg.norm(v))
    if nu < 1e-9 or nv < 1e-9:
        return 0.0
    cos = abs(float(np.dot(u, v))) / (nu * nv)
    return math.degrees(math.acos(min(1.0, cos)))


def _centroid_tangents(top_mid: np.ndarray, bot_mid: np.ndarray) -> np.ndarray:
    """Spinal centerline tangent at each vertebra (central difference of
    centroids, with forward/backward at the ends). Captures the spine's
    actual curvature without depending on per-vertebra corner predictions.
    """
    centroids = (top_mid + bot_mid) / 2.0
    n = centroids.shape[0]
    tangents = np.zeros_like(centroids)
    for i in range(n):
        if i == 0:
            tangents[i] = centroids[1] - centroids[0]
        elif i == n - 1:
            tangents[i] = centroids[-1] - centroids[-2]
        else:
            tangents[i] = centroids[i + 1] - centroids[i - 1]
    return tangents


def _draw_debug_overlay(
    img: np.ndarray,
    keypoints: list[list[list[float]]],
    upper: np.ndarray,
    lower: np.ndarray,
    top_mid: np.ndarray,
    bot_mid: np.ndarray,
    body_tilts: np.ndarray,
) -> np.ndarray:
    out = img.copy()
    n = upper.shape[0]
    for i in range(n):
        for kp in keypoints[i]:
            cv.circle(out, (int(kp[0]), int(kp[1])), 4, (0, 200, 255), -1)
        # upper endplate (TL -> TR) in green
        tl = (int(keypoints[i][0][0]), int(keypoints[i][0][1]))
        tr = (int(keypoints[i][1][0]), int(keypoints[i][1][1]))
        cv.line(out, tl, tr, (0, 255, 0), 2)
        # lower endplate (BL -> BR) in red
        bl = (int(keypoints[i][2][0]), int(keypoints[i][2][1]))
        br = (int(keypoints[i][3][0]), int(keypoints[i][3][1]))
        cv.line(out, bl, br, (0, 0, 255), 2)
        # body axis (top_mid -> bot_mid) in white
        a = (int(top_mid[i, 0]), int(top_mid[i, 1]))
        b = (int(bot_mid[i, 0]), int(bot_mid[i, 1]))
        cv.line(out, a, b, (255, 255, 255), 1)
        # index label and signed body tilt
        cx = int((top_mid[i, 0] + bot_mid[i, 0]) / 2.0)
        cy = int((top_mid[i, 1] + bot_mid[i, 1]) / 2.0)
        cv.putText(
            out,
            f"{i}:{body_tilts[i]:+.0f}",
            (cx + 8, cy),
            cv.FONT_HERSHEY_SIMPLEX,
            0.45,
            (255, 255, 0),
            1,
            cv.LINE_AA,
        )
    return out


def _print_table(
    body_tilt: np.ndarray,
    upper: np.ndarray,
    lower: np.ndarray,
    top_mid: np.ndarray,
    bot_mid: np.ndarray,
) -> None:
    print()
    print("Per-vertebra geometry (tilts in degrees from vertical / horizontal):")
    print(
        f"{'idx':>3}  {'top_mid':>14}  {'bot_mid':>14}  "
        f"{'body_tilt':>9}  {'upper_tilt':>10}  {'lower_tilt':>10}  {'wedge':>6}"
    )
    n = upper.shape[0]
    for i in range(n):
        u_h = _tilt_from_horizontal_deg(upper[i])
        l_h = _tilt_from_horizontal_deg(lower[i])
        wedge = u_h - l_h
        print(
            f"{i:>3}  "
            f"({top_mid[i, 0]:>5.0f},{top_mid[i, 1]:>5.0f})  "
            f"({bot_mid[i, 0]:>5.0f},{bot_mid[i, 1]:>5.0f})  "
            f"{body_tilt[i]:>+9.2f}  "
            f"{u_h:>+10.2f}  {l_h:>+10.2f}  {wedge:>+6.2f}"
        )


def _print_segments(
    upper: np.ndarray,
    lower: np.ndarray,
    top_mid: np.ndarray,
    bot_mid: np.ndarray,
    body_tilt: np.ndarray,
) -> None:
    inflections = _segment_inflections(body_tilt)
    print(f"\nDetected inflections (junction indices): {inflections}")

    n = upper.shape[0]
    if not inflections:
        segments: list[tuple[int, int]] = [(0, n - 1)]
    else:
        segments = []
        prev = 0
        for p in inflections:
            segments.append((prev, p))
            prev = p
        segments.append((prev, n - 1))

    seg_results = [_max_endplate_pair(upper, lower, lo, hi) for lo, hi in segments]
    print("\nInitial segments (before sub-threshold merge):")
    for (lo, hi), (deg, ti, tj) in zip(segments, seg_results):
        print(
            f"  vertebrae {lo:>2}..{hi:<2}  "
            f"endplate Cobb={deg:>5.2f} deg  pair=({ti},{tj})"
        )

    segments, seg_results = _merge_minor_segments(
        segments, seg_results, upper, lower, body_tilt
    )
    print(
        f"\nAfter merging non-structural segments "
        f"(below {_MIN_SEGMENT_COBB_DEG} deg or no apex): "
        f"{len(seg_results)} segment(s)"
    )
    centerline = _centroid_tangents(top_mid, bot_mid)
    for (lo, hi), (deg, ti, tj) in zip(segments, seg_results):
        body_axis_i = bot_mid[ti] - top_mid[ti]
        body_axis_j = bot_mid[tj] - top_mid[tj]
        body_cobb = _line_angle_unsigned(body_axis_i, body_axis_j)
        spline_cobb = _line_angle_unsigned(centerline[ti], centerline[tj])
        print(
            f"  vertebrae {lo:>2}..{hi:<2}  pair=({ti},{tj})  "
            f"endplate={deg:>5.2f}  body-axis={body_cobb:>5.2f}  "
            f"centerline-tangent={spline_cobb:>5.2f}"
        )


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("image", type=Path, help="path to AP X-ray (jpg/png/webp)")
    parser.add_argument(
        "--overlay",
        type=Path,
        default=None,
        help="optional path to write a debug overlay image",
    )
    args = parser.parse_args(argv)

    if not args.image.exists():
        print(f"image not found: {args.image}", file=sys.stderr)
        return 1

    raw = cv.imdecode(np.fromfile(str(args.image), dtype=np.uint8), cv.IMREAD_COLOR)
    if raw is None:
        print(f"could not decode image: {args.image}", file=sys.stderr)
        return 1

    bboxes, keypoints, scores = predict(raw)[0]
    if not keypoints:
        print("no vertebrae detected", file=sys.stderr)
        return 2

    n = len(keypoints)
    print(f"Detected {n} vertebrae from {args.image.name} (shape={raw.shape}).")

    flat = keypoints_to_landmark_xy(keypoints)
    ap = len(flat) // 2
    xs = list(flat[:ap])
    ys = list(flat[ap:])
    upper, lower, top_mid, bot_mid = _vertebra_geometry(xs, ys, n)
    body_tilt = _signed_body_tilt(top_mid, bot_mid)

    _print_table(body_tilt, upper, lower, top_mid, bot_mid)
    _print_segments(upper, lower, top_mid, bot_mid, body_tilt)

    cobb_list, angles, curve_type, _ = cobb_angle_cal(flat, raw.shape)
    print(f"\nReported curve_type={curve_type}, cobb_list={cobb_list}")
    print(f"angles={angles}")

    api = build_inference_api(bboxes, keypoints, scores, raw.shape)
    print(f"\nbuild_inference_api angles={api.get('angles')}")

    if args.overlay is not None:
        debug = _draw_debug_overlay(raw, keypoints, upper, lower, top_mid, bot_mid, body_tilt)
        cv.imwrite(str(args.overlay), debug)
        print(f"\nWrote debug overlay to {args.overlay}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))

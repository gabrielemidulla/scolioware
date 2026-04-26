"""Cobb angle computation from per-vertebra keypoints.

Numerically equivalent to the legacy NumPy 1.x implementation (which relied on
``np.matrix`` scalar-coercion semantics removed in NumPy 2.x). All matrix usage
has been replaced with regular ``np.ndarray`` operations and explicit scalar
casts so that ``cob_angles[i] = mt`` always receives a plain Python float.
"""

from __future__ import annotations

import math
from typing import Any

import numpy as np


def _create_angles_dict(
    pt: tuple[float, list[int]],
    mt: tuple[float, list[int]],
    tl: tuple[float, list[int]],
) -> dict[str, dict[str, Any]]:
    """Each of pt, mt, tl: (angle_deg, [top_vert_idx, bottom_vert_idx])."""
    return {
        "pt": {"angle": pt[0], "idxs": [pt[1][0], pt[1][1]]},
        "mt": {"angle": mt[0], "idxs": [mt[1][0], mt[1][1]]},
        "tl": {"angle": tl[0], "idxs": [tl[1][0], tl[1][1]]},
    }


def _isS(p: list[list[float]] | np.ndarray) -> bool:
    """True when the central column of vertebra midpoints is not monotonic
    relative to the line between the topmost and bottommost midpoints (i.e.,
    the spine has S-shape inflection)."""
    arr = np.asarray(p, dtype=float)
    num = arr.shape[0]
    if num < 3:
        return False
    top = arr[0]
    bot = arr[num - 1]
    dy = top[1] - bot[1]
    dx = top[0] - bot[0]
    if dy == 0.0 or dx == 0.0:
        return False
    ll = ((arr[: num - 2, 1] - bot[1]) / dy) - ((arr[: num - 2, 0] - bot[0]) / dx)
    s = float(np.sum(ll * ll[:, None]))
    s_abs = float(np.sum(np.abs(ll * ll[:, None])))
    return s != s_abs


def _angle_deg(a: np.ndarray, b: np.ndarray) -> float:
    """Unsigned angle (degrees) between two 2-D vectors."""
    na = float(np.linalg.norm(a))
    nb = float(np.linalg.norm(b))
    if na == 0.0 or nb == 0.0:
        return 0.0
    cos = float(np.clip(np.dot(a, b) / (na * nb), -1.0, 1.0))
    return float(np.arccos(cos)) * 180.0 / math.pi


def cobb_angle_cal(
    landmark_xy: list[float] | np.ndarray,
    image_shape: tuple[int, int, int] | tuple[int, int],
) -> tuple[list[float], dict[str, Any], str | None, list[list[list[int]]]]:
    """Cobb angles from interlaced x/y keypoints.

    ``landmark_xy`` layout: ``[x1..xN, y1..yN]`` where ``N = vnum * 4``
    (4 corner keypoints per vertebra). ``image_shape[0]`` is the image height,
    used to discriminate between S-curve sub-cases.

    Returns ``(cobb_angles_list, angles_with_pos, curve_type, midpoint_lines)``:
      * ``cobb_angles_list``: ``[pt_deg, mt_deg, tl_deg]``
      * ``angles_with_pos``: dict with per-region angle + vertebra index pair
      * ``curve_type``: ``"C"`` or ``"S"``
      * ``midpoint_lines``: per-vertebra ``[[top_x,top_y],[bot_x,bot_y]]`` ints
    """
    landmark_xy = list(landmark_xy)
    ap_num = len(landmark_xy) // 2
    vnum = ap_num // 4
    if vnum < 2:
        raise ValueError("Cobb needs at least 2 vertebrae (8 keypoints).")

    xs = landmark_xy[:ap_num]
    ys = landmark_xy[ap_num:]

    mid_p_v: list[list[float]] = []
    for i in range(ap_num // 2):
        x0, x1 = xs[2 * i], xs[2 * i + 1]
        y0, y1 = ys[2 * i], ys[2 * i + 1]
        mid_p_v.append([(x0 + x1) / 2.0, (y0 + y1) / 2.0])

    mid_p: list[list[float]] = []
    for i in range(vnum):
        x = xs[4 * i: 4 * i + 4]
        y = ys[4 * i: 4 * i + 4]
        mid_p.append([(x[0] + x[2]) / 2.0, (y[0] + y[2]) / 2.0])
        mid_p.append([(x[3] + x[1]) / 2.0, (y[3] + y[1]) / 2.0])

    vec_m = np.zeros((vnum, 2), dtype=float)
    for i in range(vnum):
        a = mid_p[2 * i]
        b = mid_p[2 * i + 1]
        vec_m[i, 0] = b[0] - a[0]
        vec_m[i, 1] = b[1] - a[1]

    dot = vec_m @ vec_m.T  # (vnum, vnum)
    norms = np.linalg.norm(vec_m, axis=1, keepdims=True)  # (vnum, 1)
    denom = norms @ norms.T  # (vnum, vnum)
    safe_denom = np.where(denom == 0.0, 1.0, denom)
    cos = np.clip(dot / safe_denom, -1.0, 1.0)
    angles = np.arccos(cos)  # (vnum, vnum), radians

    col_max = np.amax(angles, axis=0)
    col_argmax = np.argmax(angles, axis=0)
    pos2 = int(np.argmax(col_max))
    pos_top = int(col_argmax[pos2])
    pt_rad = float(col_max[pos2])
    pt_deg = pt_rad * 180.0 / math.pi

    cob_angles = np.zeros(3, dtype=float)
    cob_angles[0] = pt_deg

    angles_with_pos: dict[str, Any]
    curve_type: str | None

    if not _isS(mid_p_v):
        mt_deg = _angle_deg(vec_m[0], vec_m[pos2])
        tl_deg = _angle_deg(vec_m[vnum - 1], vec_m[pos_top])
        cob_angles[1] = mt_deg
        cob_angles[2] = tl_deg

        angles_with_pos = _create_angles_dict(
            mt=(pt_deg, [pos2, pos_top]),
            pt=(mt_deg, [0, pos2]),
            tl=(tl_deg, [pos_top, vnum - 1]),
        )
        curve_type = "C"
    else:
        upper_in_image = (
            mid_p_v[pos2 * 2][1] + mid_p_v[pos_top * 2][1]
        ) < image_shape[0]

        if upper_in_image:
            v_up = vec_m[pos2]
            seg_up = vec_m[0:pos2]
            if seg_up.shape[0] == 0:
                mt_deg = 0.0
                pos1_1 = 0
            else:
                norms_up = np.linalg.norm(seg_up, axis=1)
                safe_n_up = np.where(norms_up == 0.0, 1.0, norms_up)
                cos_up = np.clip(
                    (seg_up @ v_up) / (np.linalg.norm(v_up) * safe_n_up), -1.0, 1.0
                )
                ang_up = np.arccos(cos_up)
                pos1_1 = int(np.argmax(ang_up))
                mt_deg = float(np.amax(ang_up)) * 180.0 / math.pi
            cob_angles[1] = mt_deg

            v_dn = vec_m[pos_top]
            seg_dn = vec_m[pos_top:vnum]
            if seg_dn.shape[0] == 0:
                tl_deg = 0.0
                pos1_2_local = 0
            else:
                norms_dn = np.linalg.norm(seg_dn, axis=1)
                safe_n_dn = np.where(norms_dn == 0.0, 1.0, norms_dn)
                cos_dn = np.clip(
                    (seg_dn @ v_dn) / (np.linalg.norm(v_dn) * safe_n_dn), -1.0, 1.0
                )
                ang_dn = np.arccos(cos_dn)
                pos1_2_local = int(np.argmax(ang_dn))
                tl_deg = float(np.amax(ang_dn)) * 180.0 / math.pi
            cob_angles[2] = tl_deg

            pos1_2 = pos1_2_local + pos_top - 1

            angles_with_pos = _create_angles_dict(
                mt=(pt_deg, [pos2, pos_top]),
                pt=(mt_deg, [pos1_1, pos2]),
                tl=(tl_deg, [pos_top, pos1_2]),
            )
            curve_type = "S"
        else:
            v_up = vec_m[pos2]
            seg_up = vec_m[0:pos2]
            if seg_up.shape[0] == 0:
                mt_deg = 0.0
                pos1_1 = 0
            else:
                norms_up = np.linalg.norm(seg_up, axis=1)
                safe_n_up = np.where(norms_up == 0.0, 1.0, norms_up)
                cos_up = np.clip(
                    (seg_up @ v_up) / (np.linalg.norm(v_up) * safe_n_up), -1.0, 1.0
                )
                ang_up = np.arccos(cos_up)
                pos1_1 = int(np.argmax(ang_up))
                mt_deg = float(np.amax(ang_up)) * 180.0 / math.pi
            cob_angles[1] = mt_deg

            v_dn = vec_m[pos1_1]
            seg_dn = vec_m[0:pos1_1 + 1]
            if seg_dn.shape[0] == 0:
                tl_deg = 0.0
                pos1_2 = 0
            else:
                norms_dn = np.linalg.norm(seg_dn, axis=1)
                safe_n_dn = np.where(norms_dn == 0.0, 1.0, norms_dn)
                cos_dn = np.clip(
                    (seg_dn @ v_dn) / (np.linalg.norm(v_dn) * safe_n_dn), -1.0, 1.0
                )
                ang_dn = np.arccos(cos_dn)
                pos1_2 = int(np.argmax(ang_dn))
                tl_deg = float(np.amax(ang_dn)) * 180.0 / math.pi
            cob_angles[2] = tl_deg

            angles_with_pos = _create_angles_dict(
                tl=(pt_deg, [pos2, pos_top]),
                mt=(mt_deg, [pos1_1, pos2]),
                pt=(tl_deg, [pos1_2, pos1_1]),
            )
            curve_type = "S"

    midpoint_lines: list[list[list[int]]] = []
    for i in range(len(mid_p) // 2):
        midpoint_lines.append([
            [int(mid_p[2 * i][0]), int(mid_p[2 * i][1])],
            [int(mid_p[2 * i + 1][0]), int(mid_p[2 * i + 1][1])],
        ])

    cobb_angles_list = [float(c) for c in cob_angles]
    for k in angles_with_pos:
        angles_with_pos[k]["angle"] = float(angles_with_pos[k]["angle"])
        for j in range(len(angles_with_pos[k]["idxs"])):
            angles_with_pos[k]["idxs"][j] = int(angles_with_pos[k]["idxs"][j])

    return cobb_angles_list, angles_with_pos, curve_type, midpoint_lines


def keypoints_to_landmark_xy(keypoints: list[list[list[float]]]) -> list[float]:
    """Per-vertebra ``[[x,y], ...]`` lists → flat ``[x1..xN, y1..yN]``."""
    x_points: list[float] = []
    y_points: list[float] = []
    for kps in keypoints:
        for kp in kps:
            x_points.append(float(kp[0]))
            y_points.append(float(kp[1]))
    return x_points + y_points

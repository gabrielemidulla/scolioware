"""Cobb angles from per-vertebra keypoints (body-axis variant)."""

from __future__ import annotations

import math
from typing import Any

import numpy as np


def _create_angles_dict(
    pt: tuple[float, list[int]],
    mt: tuple[float, list[int]],
    tl: tuple[float, list[int]],
) -> dict[str, dict[str, Any]]:
    return {
        "pt": {"angle": pt[0], "idxs": [pt[1][0], pt[1][1]]},
        "mt": {"angle": mt[0], "idxs": [mt[1][0], mt[1][1]]},
        "tl": {"angle": tl[0], "idxs": [tl[1][0], tl[1][1]]},
    }


def _isS(p: list[list[float]] | np.ndarray) -> bool:
    arr = np.asarray(p, dtype=float)
    num = arr.shape[0]
    if num < 3:
        return False
    dy = arr[0, 1] - arr[num - 1, 1]
    dx = arr[0, 0] - arr[num - 1, 0]
    if dy == 0.0 or dx == 0.0:
        return False
    ll = ((arr[: num - 2, 1] - arr[num - 1, 1]) / dy
          - (arr[: num - 2, 0] - arr[num - 1, 0]) / dx).reshape(-1, 1)
    outer = ll @ ll.T
    return bool(np.sum(outer) != np.sum(np.abs(outer)))


def _max_angle_pair(sub: np.ndarray) -> tuple[float, int, int]:
    n = sub.shape[0]
    if n < 2:
        return 0.0, 0, 0
    norms = np.linalg.norm(sub, axis=1, keepdims=True)
    denom = norms @ norms.T
    safe = np.where(denom == 0.0, 1.0, denom)
    cos = np.clip((sub @ sub.T) / safe, -1.0, 1.0)
    ang = np.arccos(cos)
    flat = int(np.argmax(ang))
    i, j = divmod(flat, n)
    return float(math.degrees(ang[i, j])), int(i), int(j)


def _hx_sign_change_across_pair(hx: np.ndarray, i: int) -> bool:
    n = int(hx.shape[0])
    if i < 0 or i >= n - 1:
        return False

    def _nz(j: int) -> float | None:
        if j < 0 or j >= n:
            return None
        v = float(hx[j])
        if abs(v) < 1e-12:
            return None
        return v

    a = _nz(i)
    b = _nz(i + 1)
    if a is not None and b is not None and a * b < 0.0:
        return True
    if a is not None and b is None:
        k = i + 2
        while k < n and abs(float(hx[k])) < 1e-12:
            k += 1
        c = _nz(k) if k < n else None
        return c is not None and a * c < 0.0
    if a is None and b is not None:
        j = i - 1
        while j >= 0 and abs(float(hx[j])) < 1e-12:
            j -= 1
        d = _nz(j) if j >= 0 else None
        return d is not None and d * b < 0.0
    return False


def _find_first_sign_flip(hx: np.ndarray, lo: int, hi: int) -> int | None:
    for i in range(lo, hi):
        if _hx_sign_change_across_pair(hx, i):
            return i
    return None


def _find_last_sign_flip(hx: np.ndarray, lo: int, hi: int) -> int | None:
    for i in range(hi - 1, lo - 1, -1):
        if _hx_sign_change_across_pair(hx, i):
            return i
    return None


def cobb_angle_cal(
    landmark_xy: list[float] | np.ndarray,
    image_shape: tuple[int, int, int] | tuple[int, int],
) -> tuple[list[float], dict[str, Any], str | None, list[list[list[int]]]]:
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
        mid_p.append([(x[0] + x[1]) / 2.0, (y[0] + y[1]) / 2.0])
        mid_p.append([(x[2] + x[3]) / 2.0, (y[2] + y[3]) / 2.0])

    vec_m = np.zeros((vnum, 2), dtype=float)
    for i in range(vnum):
        a = mid_p[2 * i]
        b = mid_p[2 * i + 1]
        vec_m[i, 0] = b[0] - a[0]
        vec_m[i, 1] = b[1] - a[1]

    dot_v = vec_m @ vec_m.T
    mod = np.linalg.norm(vec_m, axis=1, keepdims=True)
    denom = mod @ mod.T
    safe_denom = np.where(denom == 0.0, 1.0, denom)
    angles = np.arccos(np.clip(dot_v / safe_denom, -1.0, 1.0))

    col_max = np.amax(angles, axis=0)
    col_argmax = np.argmax(angles, axis=0)
    pos2 = int(np.argmax(col_max))
    pt_rad = float(col_max[pos2])
    pt_deg = pt_rad * 180.0 / math.pi
    pos_other = int(col_argmax[pos2])

    cob_angles = np.zeros(3, dtype=float)
    cob_angles[0] = pt_deg

    angles_with_pos: dict[str, dict[str, Any]] = {}
    curve_type: str | None = None

    is_s_curve = _isS(mid_p_v)

    hx = vec_m[:, 0]
    u_apex = min(pos2, pos_other)
    l_apex = max(pos2, pos_other)
    infl_between: int | None = None
    split_structural_s = False
    if is_s_curve and u_apex < l_apex:
        infl_between = _find_first_sign_flip(hx, u_apex, l_apex)
        split_structural_s = infl_between is not None

    if not is_s_curve:
        v_first = vec_m[0]
        v_last = vec_m[vnum - 1]
        v_apex_u = vec_m[pos2]
        v_apex_l = vec_m[pos_other]

        n_first = float(np.linalg.norm(v_first))
        n_last = float(np.linalg.norm(v_last))
        n_apex_u = float(np.linalg.norm(v_apex_u))
        n_apex_l = float(np.linalg.norm(v_apex_l))

        d1 = float(np.dot(v_first, v_apex_u))
        d2 = float(np.dot(v_last, v_apex_l))

        mt = math.degrees(math.acos(max(-1.0, min(1.0, d1 / max(n_first * n_apex_u, 1e-12)))))
        tl = math.degrees(math.acos(max(-1.0, min(1.0, d2 / max(n_last * n_apex_l, 1e-12)))))
        cob_angles[1] = mt
        cob_angles[2] = tl

        angles_with_pos = _create_angles_dict(
            mt=(pt_deg, [pos2, pos_other]),
            pt=(mt, [0, pos2]),
            tl=(tl, [pos_other, vnum - 1]),
        )
        curve_type = "C"

    elif split_structural_s:
        assert infl_between is not None
        upper_infl = _find_last_sign_flip(hx, 0, u_apex)

        mt_start = upper_infl if upper_infl is not None else 0
        mt_end = infl_between + 1
        mt_sub = vec_m[mt_start:mt_end + 1]
        mt_deg, mi, mj = _max_angle_pair(mt_sub)
        mt_idxs = [mt_start + mi, mt_start + mj]

        next_infl = _find_first_sign_flip(hx, l_apex, vnum - 1)
        tl_start = infl_between
        tl_end = next_infl + 1 if next_infl is not None else vnum - 1
        tl_sub = vec_m[tl_start:tl_end + 1]
        tl_deg, ti, tj = _max_angle_pair(tl_sub)
        tl_idxs = [tl_start + ti, tl_start + tj]

        if upper_infl is not None:
            pt_sub = vec_m[0:upper_infl + 2]
            pt_curve_deg, pi, pj = _max_angle_pair(pt_sub)
            pt_idxs = [pi, pj]
        else:
            pt_curve_deg, pt_idxs = 0.0, [0, 0]

        cob_angles[0] = pt_curve_deg
        cob_angles[1] = mt_deg
        cob_angles[2] = tl_deg

        angles_with_pos = _create_angles_dict(
            pt=(pt_curve_deg, pt_idxs),
            mt=(mt_deg, mt_idxs),
            tl=(tl_deg, tl_idxs),
        )
        curve_type = "S"

    else:
        if pos2 > pos_other:
            pos2, pos_other = pos_other, pos2

        v_apex_u = vec_m[pos2]
        n_apex_u = float(np.linalg.norm(v_apex_u))
        seg_u = vec_m[0:pos2]
        mod_u = np.linalg.norm(seg_u, axis=1)
        safe_u = np.where(mod_u == 0.0, 1.0, mod_u)
        ang_u = np.arccos(np.clip((seg_u @ v_apex_u) / max(n_apex_u, 1e-12) / safe_u, -1.0, 1.0))
        if ang_u.size > 0:
            CobbAn1 = float(np.amax(ang_u))
            pos1_1 = int(np.argmax(ang_u))
        else:
            CobbAn1, pos1_1 = 0.0, 0
        mt = CobbAn1 * 180.0 / math.pi
        cob_angles[1] = mt

        v_apex_l = vec_m[pos_other]
        n_apex_l = float(np.linalg.norm(v_apex_l))
        seg_l = vec_m[pos_other:vnum]
        mod_l = np.linalg.norm(seg_l, axis=1)
        safe_l = np.where(mod_l == 0.0, 1.0, mod_l)
        ang_l = np.arccos(np.clip((seg_l @ v_apex_l) / max(n_apex_l, 1e-12) / safe_l, -1.0, 1.0))
        if ang_l.size > 0:
            CobbAn2 = float(np.amax(ang_l))
            pos1_2 = int(np.argmax(ang_l))
        else:
            CobbAn2, pos1_2 = 0.0, 0
        tl = CobbAn2 * 180.0 / math.pi
        cob_angles[2] = tl

        pos1_2 = pos_other + pos1_2

        angles_with_pos = _create_angles_dict(
            mt=(pt_deg, [pos2, pos_other]),
            pt=(mt, [pos1_1, pos2]),
            tl=(tl, [pos_other, pos1_2]),
        )
        curve_type = "S"

    midpoint_lines: list[list[list[int]]] = []
    for i in range(vnum):
        top = mid_p[2 * i]
        bot = mid_p[2 * i + 1]
        cx = (top[0] + bot[0]) / 2.0
        cy = (top[1] + bot[1]) / 2.0
        bx = bot[0] - top[0]
        by = bot[1] - top[1]
        L = math.hypot(bx, by)
        if L < 1e-6:
            midpoint_lines.append([
                [int(cx), int(cy)],
                [int(cx + 1), int(cy)],
            ])
            continue
        ux = -by / L
        uy = bx / L
        half = L / 2.0
        ax = cx - half * ux
        ay = cy - half * uy
        bx_end = cx + half * ux
        by_end = cy + half * uy
        midpoint_lines.append([
            [int(ax), int(ay)],
            [int(bx_end), int(by_end)],
        ])

    cobb_angles_list = [float(c) for c in cob_angles]
    for k in angles_with_pos:
        angles_with_pos[k]["angle"] = float(angles_with_pos[k]["angle"])
        for j in range(len(angles_with_pos[k]["idxs"])):
            angles_with_pos[k]["idxs"][j] = int(angles_with_pos[k]["idxs"][j])

    return cobb_angles_list, angles_with_pos, curve_type, midpoint_lines


def keypoints_to_landmark_xy(keypoints: list[list[list[float]]]) -> list[float]:
    x_points: list[float] = []
    y_points: list[float] = []
    for kps in keypoints:
        for kp in kps:
            x_points.append(float(kp[0]))
            y_points.append(float(kp[1]))
    return x_points + y_points

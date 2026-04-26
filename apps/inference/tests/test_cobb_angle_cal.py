"""S-curve Cobb zoning tests."""

import math

from src.vision.cobb_angle_cal import cobb_angle_cal


def _vert_landmark_xy(
    cx: float,
    cy_top: float,
    cy_bot: float,
    hx_body: float,
) -> list[float]:
    vy = cy_bot - cy_top
    if abs(vy) < 1e-6:
        vy = 1.0
    dx, dy = hx_body, vy
    n = math.hypot(dx, dy)
    dx, dy = dx / n, dy / n
    half_w = 8.0
    tx, ty = cx - dx * (cy_bot - cy_top) / 2.0, cy_top
    bx, by = cx + dx * (cy_bot - cy_top) / 2.0, cy_bot
    return [
        tx - half_w * dy,
        ty + half_w * dx,
        tx + half_w * dy,
        ty - half_w * dx,
        bx + half_w * dy,
        by - half_w * dx,
        bx - half_w * dy,
        by + half_w * dx,
    ]


def _stack_landmarks(per_vert: list[list[float]]) -> list[float]:
    xs: list[float] = []
    ys: list[float] = []
    for block in per_vert:
        assert len(block) == 8
        xs.extend(block[0::2])
        ys.extend(block[1::2])
    return xs + ys


def test_s_curve_split_mt_tl_stay_in_zones():
    n = 15
    per: list[list[float]] = []
    for i in range(n):
        cy_t = 20.0 + i * 22.0
        cy_b = cy_t + 18.0
        cx = 200.0 + 0.4 * i * i
        if i <= 5:
            hx_b = 5.0
        elif i >= 9:
            hx_b = -5.0 - 0.35 * float(i - 9)
        else:
            hx_b = (i - 5) / 4.0 * (-5.0) + (1 - (i - 5) / 4.0) * 5.0
        per.append(_vert_landmark_xy(cx, cy_t, cy_b, hx_b))

    flat = _stack_landmarks(per)
    cobb_list, angles, curve_type, _lines = cobb_angle_cal(flat, (800, 600, 3))

    assert curve_type == "S"
    mt_i, mt_j = angles["mt"]["idxs"]
    tl_i, tl_j = angles["tl"]["idxs"]
    mt_span = abs(mt_i - mt_j)
    assert mt_span < 11, "MT end vertebrae should lie within one structural curve, not the full spine"
    assert max(mt_i, mt_j) < n - 3, "MT should not anchor in the lowest lumbar levels"
    assert min(tl_i, tl_j) > 2, "TL should draw from the lower curve, not only the top"
    assert angles["tl"]["angle"] > 1.0, "lumbar zone should have non-trivial TL Cobb"
    assert cobb_list[0] == angles["pt"]["angle"]
    assert cobb_list[1] == angles["mt"]["angle"]
    assert cobb_list[2] == angles["tl"]["angle"]


def test_cobb_returns_three_regions():
    """Smoke: any smooth spine still yields pt/mt/tl entries."""
    n = 12
    per: list[list[float]] = []
    for i in range(n):
        cy_t = 10.0 + i * 20.0
        cy_b = cy_t + 16.0
        cx = 100.0 + 3.0 * float(i)
        per.append(_vert_landmark_xy(cx, cy_t, cy_b, 4.0 + 0.02 * float(i)))

    flat = _stack_landmarks(per)
    cobb_list, angles, _curve_type, _lines = cobb_angle_cal(flat, (600, 400, 3))
    assert len(cobb_list) == 3
    assert set(angles.keys()) >= {"pt", "mt", "tl"}

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
    """Anatomical S-curve: tilts go +X to -X to +X with one rotation
    reversal at the junction vertebra. Each curve's end vertebrae should
    stay within their own zone.
    """
    n = 15
    junction = 7
    max_tilt_deg = 15.0
    per: list[list[float]] = []
    for i in range(n):
        cy_t = 20.0 + i * 22.0
        cy_b = cy_t + 18.0
        cx = 200.0
        if i <= junction:
            t = i / float(junction)
            tilt_deg = max_tilt_deg * (1.0 - 2.0 * t)
        else:
            t = (i - junction) / float(n - 1 - junction)
            tilt_deg = -max_tilt_deg * (1.0 - 2.0 * t)
        hx_b = 22.0 * math.tan(math.radians(tilt_deg))
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
    assert angles["mt"]["angle"] > 20.0, "balanced 30-deg upper curve should give substantial MT"
    assert angles["tl"]["angle"] > 20.0, "balanced 30-deg lower curve should give substantial TL"
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


def test_smooth_single_curve_is_classified_C():
    """A monotone-tilt chain has no structural inflection -> C-curve."""
    n = 12
    per: list[list[float]] = []
    for i in range(n):
        cy_t = 10.0 + i * 20.0
        cy_b = cy_t + 16.0
        cx = 100.0 + 0.6 * float(i)
        hx_b = 4.0 + 0.4 * float(i)
        per.append(_vert_landmark_xy(cx, cy_t, cy_b, hx_b))
    flat = _stack_landmarks(per)
    _cobb, _angles, curve_type, _lines = cobb_angle_cal(flat, (600, 400, 3))
    assert curve_type == "C"


def test_triple_curve_assigns_pt_mt_tl_top_to_bottom():
    """Triple curve with two rotation reversals -> PT/MT/TL populated.

    The tilt profile mimics three alternating curves connected at two
    junction vertebrae (the most-rotated levels). The middle curve is
    given the largest tilt swing so it remains MT regardless of the
    magnitudes of PT and TL. Junction vertebrae sit at the boundaries
    between segments, so PT/MT and MT/TL idx ranges may touch at a
    junction (compared with ``<=``, not ``<``).
    """
    tilts_deg = [
        +8.0, +4.0, 0.0, -4.0, -8.0,
        -4.7, -1.3, +2.0, +5.3, +8.7,
        +18.0,
        +12.0, +6.0, 0.0, -6.0,
    ]
    n = len(tilts_deg)
    per: list[list[float]] = []
    for i in range(n):
        cy_t = 20.0 + i * 22.0
        cy_b = cy_t + 18.0
        cx = 200.0
        hx_b = 22.0 * math.tan(math.radians(tilts_deg[i]))
        per.append(_vert_landmark_xy(cx, cy_t, cy_b, hx_b))

    flat = _stack_landmarks(per)
    cobb_list, angles, curve_type, _lines = cobb_angle_cal(flat, (800, 600, 3))

    assert curve_type == "S"
    pt_i, pt_j = angles["pt"]["idxs"]
    mt_i, mt_j = angles["mt"]["idxs"]
    tl_i, tl_j = angles["tl"]["idxs"]
    assert max(pt_i, pt_j) <= min(mt_i, mt_j), "PT must sit above MT"
    assert max(mt_i, mt_j) <= min(tl_i, tl_j), "MT must sit above TL"
    assert angles["pt"]["angle"] > 1.0
    assert angles["tl"]["angle"] > 1.0
    assert angles["mt"]["angle"] > angles["pt"]["angle"]
    assert angles["mt"]["angle"] > angles["tl"]["angle"]
    assert cobb_list[0] == angles["pt"]["angle"]
    assert cobb_list[1] == angles["mt"]["angle"]
    assert cobb_list[2] == angles["tl"]["angle"]


def test_small_wobble_does_not_flip_curve_type_to_S():
    """A monotone curve with tiny noise (|tilt| < 3 deg) on one vertebra
    must not be misclassified as an S-curve.
    """
    n = 12
    per: list[list[float]] = []
    for i in range(n):
        cy_t = 20.0 + i * 22.0
        cy_b = cy_t + 18.0
        cx = 200.0 + 0.5 * float(i)
        hx_b = 5.0 if i != 6 else -0.4
        per.append(_vert_landmark_xy(cx, cy_t, cy_b, hx_b))

    flat = _stack_landmarks(per)
    _cobb, _angles, curve_type, _lines = cobb_angle_cal(flat, (700, 500, 3))
    assert curve_type == "C"


def test_subthreshold_top_wobble_is_merged_into_mt():
    """A two-vertebra opposite-rotation bump at the very top survives
    the median filter (so a spurious early inflection is reported) but
    yields a segment Cobb under 10 degrees. The merge step must fold
    it into the main thoracic curve so MT covers the full upper curve
    and only the structural S is reported. This is the bad.webp class
    of failure: a small noisy reversal at the chain's end was clipping
    several degrees off the dominant curve.
    """
    tilts_deg = [
        0.0, 4.0, 8.0,
        0.0, -5.0, -10.0, -15.0, -20.0, -25.0,
        -22.0, -15.0, -8.0, 0.0, 8.0, 15.0, 22.0,
    ]
    n = len(tilts_deg)
    per: list[list[float]] = []
    for i in range(n):
        cy_t = 20.0 + i * 22.0
        cy_b = cy_t + 18.0
        cx = 200.0
        hx_b = 22.0 * math.tan(math.radians(tilts_deg[i]))
        per.append(_vert_landmark_xy(cx, cy_t, cy_b, hx_b))

    flat = _stack_landmarks(per)
    cobb_list, angles, curve_type, _lines = cobb_angle_cal(flat, (900, 600, 3))

    assert curve_type == "S", "real anatomical S must survive the merge step"
    assert angles["pt"]["angle"] == 0.0, "sub-threshold top wobble must be merged into MT"
    assert angles["mt"]["angle"] > 25.0, "merged MT must capture the full upper curve"
    assert angles["tl"]["angle"] > 30.0, "TL must reach the junction"
    assert cobb_list[1] == angles["mt"]["angle"]
    assert cobb_list[2] == angles["tl"]["angle"]


def test_straight_spine_reports_no_scoliosis():
    """A spine with body-axis tilts within +/- 3 deg of vertical (normal
    asymmetry, not a curve) must not be reported as scoliosis. Without a
    clinical-threshold gate the max-pair search picks up endplate jitter
    and reports a phantom MT in the few-degree range, often anchored on
    the cervical vertebrae where the model fits least stable corners.
    Scoliosis is defined as Cobb >= 10 deg, so anything below that on a
    single-segment spine should yield zero angles and no curve_type.
    """
    tilts_deg = [
        +2.5, +0.8, +2.1, +0.5, -2.8, -2.4, -2.3, -0.9,
        +0.5, -1.7, -0.7, -2.9, -0.2, +1.2, -0.8, +0.5, +0.4,
    ]
    n = len(tilts_deg)
    per: list[list[float]] = []
    for i in range(n):
        cy_t = 20.0 + i * 22.0
        cy_b = cy_t + 18.0
        cx = 200.0
        hx_b = 22.0 * math.tan(math.radians(tilts_deg[i]))
        per.append(_vert_landmark_xy(cx, cy_t, cy_b, hx_b))

    flat = _stack_landmarks(per)
    cobb_list, angles, curve_type, _lines = cobb_angle_cal(flat, (900, 600, 3))

    assert curve_type is None, "straight spine must not be classified as a curve"
    assert cobb_list == [0.0, 0.0, 0.0]
    for region in ("pt", "mt", "tl"):
        assert angles[region]["angle"] == 0.0
        assert angles[region]["idxs"] == [0, 0]


def test_dominant_c_curve_with_compensatory_tails_stays_C():
    """Full-body X-ray pattern: one large structural curve flanked by
    short compensatory tails where the body axis returns toward vertical
    without producing an opposing apex. The tails create rotation-rate
    inflections at the dominant curve's end vertebrae (because rotation
    *into* the curve flips to rotation *out of* it there), so a naive
    inflection-based segmenter splits one C into a triple curve. Each
    tail's tilts stay on the same side of vertical (or barely cross by
    a degree or two), so neither contains a real apex and both must be
    folded back into the dominant curve.

    Tilt profile mimics the user's full-body X-ray: top of spine leans
    slightly left, deepens to the left limb of the main curve, swings
    through zero to the right limb, then returns toward vertical at
    L4-L5 without continuing into a real lumbar counter-curve.
    """
    tilts_deg = [
        -8.0, -6.0, -14.0, -21.0, -27.0,
        -26.0, -23.0, -13.0, +2.0, +22.0, +35.0, +37.0,
        +34.0, +24.0, +16.0, +5.0, -1.0,
    ]
    n = len(tilts_deg)
    per: list[list[float]] = []
    for i in range(n):
        cy_t = 20.0 + i * 22.0
        cy_b = cy_t + 18.0
        cx = 200.0
        hx_b = 22.0 * math.tan(math.radians(tilts_deg[i]))
        per.append(_vert_landmark_xy(cx, cy_t, cy_b, hx_b))

    flat = _stack_landmarks(per)
    cobb_list, angles, curve_type, _lines = cobb_angle_cal(flat, (900, 600, 3))

    assert curve_type == "C", (
        "tails without an opposing apex must collapse into the dominant curve"
    )
    assert angles["pt"]["angle"] == 0.0
    assert angles["tl"]["angle"] == 0.0
    assert angles["mt"]["angle"] > 50.0
    assert cobb_list[0] == 0.0
    assert cobb_list[2] == 0.0


def test_skewed_quad_uses_endplate_not_body_axis():
    """When the predicted quad is skewed (top edge tilted differently
    from the body axis), Cobb should be measured between endplates, so
    two vertebrae with parallel body axes but different endplate tilts
    must yield a non-zero Cobb angle.
    """
    cx = 200.0

    def _block(cy_t: float, top_tilt_deg: float) -> list[float]:
        cy_b = cy_t + 18.0
        half_w = 8.0
        a = math.radians(top_tilt_deg)
        ux, uy = math.cos(a), math.sin(a)
        return [
            cx - half_w * ux, cy_t - half_w * uy,
            cx + half_w * ux, cy_t + half_w * uy,
            cx - half_w, cy_b,
            cx + half_w, cy_b,
        ]

    per = [_block(20.0, 0.0)]
    for i in range(1, 6):
        per.append(_block(20.0 + i * 22.0, 0.0))
    per.append(_block(20.0 + 6 * 22.0, 15.0))
    for i in range(7, 10):
        per.append(_block(20.0 + i * 22.0, 0.0))

    flat = _stack_landmarks(per)
    cobb_list, angles, _curve_type, _lines = cobb_angle_cal(flat, (700, 500, 3))
    assert max(cobb_list) > 5.0, "endplate-skew should produce a measurable Cobb"
    assert any(15.0 - 1.0 <= angles[k]["angle"] <= 15.0 + 1.0 for k in ("pt", "mt", "tl"))

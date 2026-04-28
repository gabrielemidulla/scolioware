"""Cobb angles from per-vertebra keypoints (endplate-based variant).

The legacy implementation derived Cobb measurements from the body-axis
vector connecting the top and bottom midpoints of each vertebra. That is
only equivalent to the clinical endplate-tangent measurement when each
vertebra is a perfect rectangle. This version measures the angle directly
between endplate directions extracted from the four corner keypoints, so
the result remains correct even when the predicted quad is skewed.

Curves are separated by rotation-rate reversals, not by tilt sign-flips.
Within a single anatomical curve the body-axis tilt naturally crosses
zero at the apex (top end vertebra leans one way, apex is vertical,
bottom end vertebra leans the other way), so a tilt sign-flip alone
cannot tell a single C-curve apart from an S-curve. A real curve-to-curve
junction is instead the vertebra where the rotation rate (the diff of
consecutive tilts) reverses sign: tilts had been heading in one direction
and start heading the opposite way. Single-vertebra keypoint noise can
fake such a reversal, so the tilt sequence is median-filtered with a
small window before the diffs are computed. The junction vertebra is
clinically shared between the two adjacent curves (it is the lower end
vertebra of the upper curve and the upper end vertebra of the lower
curve), so segments overlap at every inflection.

After segmentation, candidate curves are validated for structural
plausibility. A real curve has tilts that swing through both signs
(top end leans toward concavity, apex is vertical, bottom end leans
toward concavity in the opposite direction); a segment lacking this
two-sided swing is the ascending or descending limb of a neighbouring
curve, not a curve of its own, so it is folded back into its larger
neighbour. This stops a dominant single C-curve from being reported as
a triple curve when the rotation-rate inflections sit at the C-curve's
end vertebrae rather than at junctions between distinct curves.
"""

from __future__ import annotations

import math
from typing import Any

import numpy as np

# Rotation-rate magnitudes (degrees of tilt change between consecutive
# vertebrae) below this value count as no rotation. The threshold filters
# residual jitter after median filtering without suppressing real curve
# transitions, where per-step rotation is several degrees.
_ROTATION_NEUTRAL_DEG = 1.5

# Window for the median filter applied to the tilt sequence before the
# diff is taken. Window 3 collapses any single-vertebra outlier to its
# neighbours' value, which kills wobble-fabricated inflections without
# attenuating real two-vertebra-or-longer rotations.
_TILT_MEDIAN_WINDOW = 3

# A detected inflection is snapped to the local extremum of |tilt|
# within this many vertebrae of the diff-based detection point. The
# median filter that suppresses wobbles also flattens the sharp peak at
# the true junction, so the diff sign-change typically lands one
# vertebra after the actual most-rotated level; refining brings it back.
_INFLECTION_REFINE_WINDOW = 1

# Segments whose Cobb falls below this magnitude are merged into the
# neighbour with the larger Cobb. Below ~10 deg a curve is not counted
# as structural in clinical practice, and a sub-threshold segment is in
# practice almost always either a noisy rotation reversal at the spine
# ends or a single-vertebra artefact rather than a real compensatory
# curve. Folding it back gives the surviving curve access to its true
# end-vertebrae and stops the reported Cobb from collapsing.
_MIN_SEGMENT_COBB_DEG = 10.0

# Minimum body-axis tilt magnitude (degrees from vertical) required at
# both poles of a segment for it to count as a structural curve. A
# genuine scoliosis curve has end vertebrae that lean toward the
# concavity in opposing directions with a vertical apex in between, so
# the segment must contain BOTH a substantially positive tilt and a
# substantially negative tilt. Segments that fail this test are the
# ascending or descending limb of a larger curve, not a curve in their
# own right; the rotation-rate inflection that created them sat at an
# end vertebra of the dominant curve rather than at a junction between
# two curves. Merging them back lets the dominant curve span its full
# extent and prevents a single C-curve being reported as a triple curve
# where two of the "curves" are really just halves of the main one.
_APEX_TILT_MIN_DEG = 4.0


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


def _vertebra_geometry(
    xs: list[float], ys: list[float], vnum: int
) -> tuple[np.ndarray, np.ndarray, np.ndarray, np.ndarray]:
    """Return (upper_dir, lower_dir, top_mid, bot_mid) per vertebra.

    ``upper_dir`` is ``kp[1] - kp[0]`` and ``lower_dir`` is ``kp[3] - kp[2]``.
    For the renderer's ``[TL, TR, BL, BR]`` convention the two vectors are
    naturally co-oriented; for any input that lists the bottom corners in
    swapped order they will be anti-parallel. We force them to share a
    sense per vertebra by flipping ``lower_dir`` when it is anti-parallel
    to ``upper_dir``, which keeps later averaging meaningful and is a
    no-op for the angle calculation (which treats each endplate as an
    undirected line via ``|cos|``).
    """
    upper = np.zeros((vnum, 2), dtype=float)
    lower = np.zeros((vnum, 2), dtype=float)
    top_mid = np.zeros((vnum, 2), dtype=float)
    bot_mid = np.zeros((vnum, 2), dtype=float)
    for i in range(vnum):
        x0, x1, x2, x3 = xs[4 * i: 4 * i + 4]
        y0, y1, y2, y3 = ys[4 * i: 4 * i + 4]
        upper[i] = (x1 - x0, y1 - y0)
        lower[i] = (x3 - x2, y3 - y2)
        top_mid[i] = ((x0 + x1) / 2.0, (y0 + y1) / 2.0)
        bot_mid[i] = ((x2 + x3) / 2.0, (y2 + y3) / 2.0)
    flip = np.sum(upper * lower, axis=1) < 0.0
    lower[flip] *= -1.0
    return upper, lower, top_mid, bot_mid


def _line_angle_deg(u: np.ndarray, v: np.ndarray) -> float:
    """Unsigned angle in degrees in [0, 90] between two undirected lines."""
    nu = float(np.linalg.norm(u))
    nv = float(np.linalg.norm(v))
    if nu < 1e-9 or nv < 1e-9:
        return 0.0
    cos = abs(float(np.dot(u, v))) / (nu * nv)
    return math.degrees(math.acos(min(1.0, cos)))


def _max_endplate_pair(
    upper: np.ndarray, lower: np.ndarray, lo: int, hi: int
) -> tuple[float, int, int]:
    """Within ``[lo, hi]`` inclusive, find ``(i, j)`` with ``i <= j`` that
    maximises the angle between vertebra ``i``'s upper endplate and
    vertebra ``j``'s lower endplate. Mirrors the clinical Cobb pairing
    rule (top-end-vertebra superior endplate vs bottom-end-vertebra
    inferior endplate).
    """
    best_ang, best_i, best_j = 0.0, lo, lo
    for i in range(lo, hi + 1):
        for j in range(i, hi + 1):
            a = _line_angle_deg(upper[i], lower[j])
            if a > best_ang:
                best_ang, best_i, best_j = a, i, j
    return best_ang, best_i, best_j


def _signed_body_tilt(top_mid: np.ndarray, bot_mid: np.ndarray) -> np.ndarray:
    """Signed body-axis tilt (degrees) from vertical, positive when the
    vertebra leans rightward in screen coordinates.
    """
    axis = bot_mid - top_mid
    return np.degrees(np.arctan2(axis[:, 0], axis[:, 1]))


def _median_filter(values: np.ndarray, window: int) -> np.ndarray:
    """1-D median filter with edge-clamped windows.

    Centered window of ``window`` samples; near the boundaries the window
    is truncated rather than reflected so that the very first and last
    samples are not pulled toward the interior. The default window of 3
    is enough to flatten any single-vertebra outlier.
    """
    n = int(values.shape[0])
    half = window // 2
    out = np.empty(n, dtype=float)
    for i in range(n):
        lo = max(0, i - half)
        hi = min(n, i + half + 1)
        out[i] = float(np.median(values[lo:hi]))
    return out


def _segment_inflections(tilts: np.ndarray) -> list[int]:
    """Vertex indices where the rotation rate reverses sign.

    A junction between two anatomical curves is the vertebra at which
    body-axis rotation flips direction. We detect it by looking at the
    sign of consecutive tilt diffs, after median-filtering the tilt
    sequence to suppress single-vertebra wobbles. Diffs whose magnitude
    falls below ``_ROTATION_NEUTRAL_DEG`` are treated as no rotation and
    do not break a same-sign run.

    The returned indices are diff-space detection points and are then
    snapped to the nearest local extremum of |tilt| via
    ``_refine_inflection_location``, which compensates for the
    half-vertebra phase shift the median filter introduces around a
    sharp junction peak. After refinement, duplicate or boundary
    indices are dropped.
    """
    n = int(tilts.shape[0])
    if n < 3:
        return []
    smoothed = _median_filter(tilts, _TILT_MEDIAN_WINDOW)
    diffs = np.diff(smoothed)
    raw: list[int] = []
    last_sign = 0
    for k in range(int(diffs.shape[0])):
        d = float(diffs[k])
        if d > _ROTATION_NEUTRAL_DEG:
            sign = 1
        elif d < -_ROTATION_NEUTRAL_DEG:
            sign = -1
        else:
            continue
        if last_sign != 0 and sign != last_sign:
            raw.append(k)
        last_sign = sign
    refined: list[int] = []
    for k in raw:
        p = _refine_inflection_location(tilts, k, _INFLECTION_REFINE_WINDOW)
        if 0 < p < n - 1 and (not refined or p > refined[-1]):
            refined.append(p)
    return refined


def _refine_inflection_location(
    tilts: np.ndarray, k: int, window: int
) -> int:
    """Snap ``k`` to the index in ``[k - window, k + window]`` whose
    |tilt| is largest. The true junction sits at the most-rotated
    vertebra; the median filter that suppresses wobbles also clips that
    peak, so without refinement the reported Cobb pair on either side
    of the junction loses access to the actual end vertebra and the
    angle collapses by several degrees.
    """
    n = int(tilts.shape[0])
    lo = max(0, k - window)
    hi = min(n - 1, k + window)
    best = k
    best_mag = abs(float(tilts[k])) if 0 <= k < n else -1.0
    for i in range(lo, hi + 1):
        m = abs(float(tilts[i]))
        if m > best_mag:
            best_mag = m
            best = i
    return best


def _segment_has_apex(
    body_tilt: np.ndarray, lo: int, hi: int, min_swing: float
) -> bool:
    """A segment qualifies as a structural curve when its tilts swing
    through both signs with substantial magnitude on each side. The end
    vertebrae of a real scoliosis curve lean toward the concavity in
    opposing directions, with the apex vertebra vertical between them;
    a segment that lacks tilts of either sign above the swing threshold
    is the ascending or descending limb of a bigger neighbouring curve,
    not a curve of its own.
    """
    if hi < lo:
        return False
    seg = body_tilt[lo:hi + 1]
    if seg.size == 0:
        return False
    return float(seg.max()) >= min_swing and float(seg.min()) <= -min_swing


def _pick_merge_partner(
    seg_results: list[tuple[float, int, int]], idx: int
) -> int:
    """Return the index of the neighbour to merge ``idx`` into. Prefer
    the larger neighbour so a non-structural fragment is folded into
    the dominant adjacent curve rather than fragmenting it further.
    """
    if idx == 0:
        return 1
    if idx == len(seg_results) - 1:
        return idx - 1
    left = seg_results[idx - 1][0]
    right = seg_results[idx + 1][0]
    return idx - 1 if left >= right else idx + 1


def _apply_merge(
    segments: list[tuple[int, int]],
    seg_results: list[tuple[float, int, int]],
    upper: np.ndarray,
    lower: np.ndarray,
    src: int,
    partner: int,
) -> tuple[list[tuple[int, int]], list[tuple[float, int, int]]]:
    lo = min(segments[src][0], segments[partner][0])
    hi = max(segments[src][1], segments[partner][1])
    merged_seg = (lo, hi)
    merged_result = _max_endplate_pair(upper, lower, lo, hi)
    first = min(src, partner)
    last = max(src, partner)
    del segments[last]
    del seg_results[last]
    segments[first] = merged_seg
    seg_results[first] = merged_result
    return segments, seg_results


def _merge_minor_segments(
    segments: list[tuple[int, int]],
    seg_results: list[tuple[float, int, int]],
    upper: np.ndarray,
    lower: np.ndarray,
    body_tilt: np.ndarray,
) -> tuple[list[tuple[int, int]], list[tuple[float, int, int]]]:
    """Fold non-structural segments into the neighbour with the larger
    Cobb, repeating until every surviving segment is structural or only
    one segment remains. Two failure modes are merged here:

    1. Sub-threshold Cobb (< ``_MIN_SEGMENT_COBB_DEG``): short
       noise-driven reversals at the chain's ends or single-vertebra
       artefacts. Folding them back stops the dominant curve being
       silently clipped by the spurious split.
    2. No apex within the segment: tilts that never cross zero with
       substantial swing on both sides. The rotation-rate inflection
       that created the boundary sat at an end vertebra of the
       dominant curve, not at a junction between two curves, so the
       segment is really half of a single C-curve. Folding it back
       lets that C-curve span its full extent and prevents reporting
       a triple curve when only one structural curve exists.

    Sub-threshold Cobb is checked first because a tiny-Cobb segment is
    almost always a noise artefact regardless of its tilt structure;
    apex absence is checked second so that the dominant curve has
    already absorbed any tiny end-noise before we evaluate whether the
    survivors are limbs or genuine curves.
    """
    segments = list(segments)
    seg_results = list(seg_results)
    while len(seg_results) > 1:
        min_idx = -1
        min_val = _MIN_SEGMENT_COBB_DEG
        for i, (deg, _ti, _tj) in enumerate(seg_results):
            if deg < min_val:
                min_idx = i
                min_val = deg
        if min_idx < 0:
            break
        partner = _pick_merge_partner(seg_results, min_idx)
        segments, seg_results = _apply_merge(
            segments, seg_results, upper, lower, min_idx, partner
        )
    while len(seg_results) > 1:
        non_apex_idx = -1
        for i, (lo, hi) in enumerate(segments):
            if not _segment_has_apex(body_tilt, lo, hi, _APEX_TILT_MIN_DEG):
                non_apex_idx = i
                break
        if non_apex_idx < 0:
            break
        partner = _pick_merge_partner(seg_results, non_apex_idx)
        segments, seg_results = _apply_merge(
            segments, seg_results, upper, lower, non_apex_idx, partner
        )
    return segments, seg_results


def _midpoint_lines(
    upper: np.ndarray,
    lower: np.ndarray,
    top_mid: np.ndarray,
    bot_mid: np.ndarray,
) -> list[list[list[int]]]:
    """One short line per vertebra, drawn through the centroid along the
    average endplate direction. Using the average (upper + lower) keeps
    the rendered tangent consistent with the angle that was computed,
    even when the predicted quad is non-rectangular.
    """
    vnum = int(upper.shape[0])
    lines: list[list[list[int]]] = []
    for i in range(vnum):
        cx = (top_mid[i, 0] + bot_mid[i, 0]) / 2.0
        cy = (top_mid[i, 1] + bot_mid[i, 1]) / 2.0
        ux = (upper[i, 0] + lower[i, 0]) / 2.0
        uy = (upper[i, 1] + lower[i, 1]) / 2.0
        L = math.hypot(ux, uy)
        if L < 1e-6:
            ax_len = bot_mid[i, 0] - top_mid[i, 0]
            ay_len = bot_mid[i, 1] - top_mid[i, 1]
            La = math.hypot(ax_len, ay_len)
            if La < 1e-6:
                lines.append([[int(cx), int(cy)], [int(cx + 1), int(cy)]])
                continue
            ux, uy, L = -ay_len, ax_len, La
        ux /= L
        uy /= L
        ax_len = bot_mid[i, 0] - top_mid[i, 0]
        ay_len = bot_mid[i, 1] - top_mid[i, 1]
        half = math.hypot(ax_len, ay_len) / 2.0
        lines.append(
            [
                [int(cx - half * ux), int(cy - half * uy)],
                [int(cx + half * ux), int(cy + half * uy)],
            ]
        )
    return lines


def cobb_angle_cal(
    landmark_xy: list[float] | np.ndarray,
    image_shape: tuple[int, int, int] | tuple[int, int],
) -> tuple[list[float], dict[str, Any], str | None, list[list[list[int]]]]:
    """Compute PT/MT/TL Cobb angles from a flat keypoint list.

    Returns:
        (cobb_angles, angles_with_pos, curve_type, midpoint_lines)

        ``cobb_angles`` is ``[pt, mt, tl]`` in degrees. ``angles_with_pos``
        carries the same magnitudes plus the ``[top_idx, bottom_idx]``
        end-vertebra pair used for each region. ``curve_type`` is ``"C"``
        for chains without a structural inflection and ``"S"`` otherwise.
        ``midpoint_lines`` is one tangent segment per vertebra for
        rendering.
    """
    landmark_xy = list(landmark_xy)
    ap_num = len(landmark_xy) // 2
    vnum = ap_num // 4
    if vnum < 2:
        raise ValueError("Cobb needs at least 2 vertebrae (8 keypoints).")

    xs = list(landmark_xy[:ap_num])
    ys = list(landmark_xy[ap_num:])

    upper, lower, top_mid, bot_mid = _vertebra_geometry(xs, ys, vnum)
    body_tilt = _signed_body_tilt(top_mid, bot_mid)
    inflections = _segment_inflections(body_tilt)

    if not inflections:
        segments: list[tuple[int, int]] = [(0, vnum - 1)]
    else:
        # Junction-overlapping segments. Each junction vertebra is the
        # lower end vertebra of the upper curve and the upper end
        # vertebra of the lower curve, so it must be visible to the
        # max-angle search on both sides; otherwise the segment that
        # excludes it loses access to its most-rotated vertebra and the
        # measured Cobb collapses.
        segments = []
        prev = 0
        for p in inflections:
            segments.append((prev, p))
            prev = p
        segments.append((prev, vnum - 1))

    seg_results = [_max_endplate_pair(upper, lower, lo, hi) for lo, hi in segments]
    segments, seg_results = _merge_minor_segments(
        segments, seg_results, upper, lower, body_tilt
    )
    n_seg = len(seg_results)

    pt_deg, pt_idxs = 0.0, [0, 0]
    mt_deg, mt_idxs = 0.0, [0, 0]
    tl_deg, tl_idxs = 0.0, [0, 0]

    if n_seg == 1:
        deg, i, j = seg_results[0]
        # Scoliosis is clinically defined as Cobb >= 10 deg. A single
        # segment below that threshold is not a curve at all; the
        # max-pair search merely picked up endplate jitter from a
        # straight spine. The merge loop only fires when more than one
        # segment exists, so a sub-threshold lone segment otherwise
        # slips through and gets rendered as a tiny phantom MT (often
        # in the cervical region, where the model fits less stable
        # endplates). Reporting zero with no curve_type matches the
        # clinical reading "no scoliosis detected".
        if deg < _MIN_SEGMENT_COBB_DEG:
            curve_type: str | None = None
        else:
            mt_deg, mt_idxs = deg, [i, j]
            curve_type = "C"
    elif n_seg == 2:
        u_deg, ui, uj = seg_results[0]
        l_deg, li, lj = seg_results[1]
        mt_deg, mt_idxs = u_deg, [ui, uj]
        tl_deg, tl_idxs = l_deg, [li, lj]
        curve_type = "S"
    else:
        # Three or more zones: outermost segments become PT and TL; the
        # structurally dominant middle zone (largest Cobb) becomes MT.
        top_deg, ti, tj = seg_results[0]
        bot_deg, bi, bj = seg_results[-1]
        mid_idx = max(range(1, n_seg - 1), key=lambda k: seg_results[k][0])
        mid_deg, mi, mj = seg_results[mid_idx]
        pt_deg, pt_idxs = top_deg, [ti, tj]
        mt_deg, mt_idxs = mid_deg, [mi, mj]
        tl_deg, tl_idxs = bot_deg, [bi, bj]
        curve_type = "S"

    angles_with_pos = _create_angles_dict(
        pt=(pt_deg, pt_idxs),
        mt=(mt_deg, mt_idxs),
        tl=(tl_deg, tl_idxs),
    )
    cob_angles = [pt_deg, mt_deg, tl_deg]
    midpoint_lines = _midpoint_lines(upper, lower, top_mid, bot_mid)

    cobb_angles_list = [float(c) for c in cob_angles]
    for k in angles_with_pos:
        angles_with_pos[k]["angle"] = float(angles_with_pos[k]["angle"])
        angles_with_pos[k]["idxs"] = [int(v) for v in angles_with_pos[k]["idxs"]]

    return cobb_angles_list, angles_with_pos, curve_type, midpoint_lines


def keypoints_to_landmark_xy(keypoints: list[list[list[float]]]) -> list[float]:
    x_points: list[float] = []
    y_points: list[float] = []
    for kps in keypoints:
        for kp in kps:
            x_points.append(float(kp[0]))
            y_points.append(float(kp[1]))
    return x_points + y_points

"""Tests for the auto-crop helpers in ``spine_net_infer``.

These cover the pure functions only; ``predict`` itself needs the model
weights and a real image, so it lives in the diagnostic CLI under
``bin/cobb_debug.py`` rather than the unit-test suite.
"""

from __future__ import annotations

from src.vision.spine_net_infer import (
    SPINE_AUTOCROP_MIN_VERTS,
    _shifted_detections,
    _spine_crop_box,
)


def _bbox(x0: int, y0: int, x1: int, y1: int) -> list[int]:
    return [x0, y0, x1, y1]


def test_spine_crop_box_skipped_when_already_tight():
    """An image where detected vertebrae span the whole frame should not
    trigger a second pass; cropping the same region back out wastes a
    forward pass without adding resolution.
    """
    h, w = 1000, 500
    bboxes = [_bbox(20 + i, 10 + i * 90, 480 - i, 90 + i * 90) for i in range(10)]
    box = _spine_crop_box(bboxes, (h, w, 3))
    assert box is None


def test_spine_crop_box_triggers_on_narrow_spine():
    """A square full-body X-ray with the spine in a narrow column should
    return a crop that is materially smaller than the input. This is the
    regression case that the auto-crop pipeline exists to fix.
    """
    h, w = 630, 630
    bboxes = [_bbox(290, 60 + i * 30, 340, 90 + i * 30) for i in range(10)]
    box = _spine_crop_box(bboxes, (h, w, 3))
    assert box is not None
    x0, y0, x1, y1 = box
    assert 0 <= x0 < x1 <= w
    assert 0 <= y0 < y1 <= h
    crop_w = x1 - x0
    assert crop_w < w * 0.6, "crop should drop the lateral whitespace"
    assert y0 < 60, "vertical padding should reach above the topmost vertebra"
    assert y1 > 90 + 9 * 30, "vertical padding should reach below the bottom vertebra"


def test_spine_crop_box_skipped_when_too_few_detections():
    """The first pass needs to find a few vertebrae before we can trust
    its bounding box. Two stray detections might be false positives on
    soft tissue and would yield a useless crop.
    """
    h, w = 800, 800
    bboxes = [_bbox(100, 100, 200, 200), _bbox(150, 300, 250, 400)]
    assert len(bboxes) < SPINE_AUTOCROP_MIN_VERTS
    assert _spine_crop_box(bboxes, (h, w, 3)) is None


def test_spine_crop_box_clamps_to_image_bounds():
    """Padding must not push the crop outside the image — negative
    indices or out-of-range slices would silently return empty crops.
    """
    h, w = 200, 200
    bboxes = [_bbox(0, 0, 80, 30 + i * 25) for i in range(5)]
    box = _spine_crop_box(bboxes, (h, w, 3))
    if box is not None:
        x0, y0, x1, y1 = box
        assert x0 >= 0 and y0 >= 0
        assert x1 <= w and y1 <= h


def test_shifted_detections_translates_bboxes_and_keypoints():
    bboxes = [_bbox(10, 20, 40, 60), _bbox(15, 70, 45, 110)]
    keypoints = [
        [[12.0, 22.0], [38.0, 22.0], [12.0, 58.0], [38.0, 58.0]],
        [[16.0, 72.0], [44.0, 72.0], [16.0, 108.0], [44.0, 108.0]],
    ]
    out_b, out_k = _shifted_detections(bboxes, keypoints, dx=100, dy=200)
    assert out_b == [[110, 220, 140, 260], [115, 270, 145, 310]]
    assert out_k[0][0] == [112.0, 222.0]
    assert out_k[0][3] == [138.0, 258.0]
    assert out_k[1][2] == [116.0, 308.0]

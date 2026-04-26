import numpy as np
from src.vision.landmark_recompute import flat_to_keypoints, recompute_from_flat_landmarks


def test_flat_to_keypoints_inferred_17():
    flat = [0.0] * (17 * 4 * 2)
    k = flat_to_keypoints(flat)
    assert len(k) == 17
    assert len(k[0]) == 4
    assert len(k[0][0]) == 2


def test_flat_to_keypoints_18_vertices():
    flat = [0.0] * (18 * 4 * 2)
    k = flat_to_keypoints(flat)
    assert len(k) == 18


def test_flat_to_keypoints_explicit_n_verts_truncates():
    flat = [0.0] * (17 * 4 * 2)
    k = flat_to_keypoints(flat, n_verts=10)
    assert len(k) == 10


def test_recompute_from_flat_no_crash():
    h, w = 512, 256
    bgr = np.zeros((h, w, 3), dtype=np.uint8)
    flat: list[float] = []
    for v in range(17):
        x0 = 20.0 + v * 2.0
        for _kp in range(4):
            flat.extend([x0, 20.0 + 8.0 * _kp])
    _api, _err = recompute_from_flat_landmarks(flat, bgr)
    assert _err is None or isinstance(_err, str)

import numpy as np
from src.landmark_recompute import flat_to_keypoints, recompute_from_flat_landmarks


def test_flat_to_keypoints_len():
    flat = [0.0] * (18 * 4 * 2)
    k = flat_to_keypoints(flat)
    assert len(k) == 18
    assert len(k[0]) == 4


def test_recompute_from_flat_no_crash():
    h, w = 512, 256
    bgr = np.zeros((h, w, 3), dtype=np.uint8)
    flat: list[float] = []
    for v in range(18):
        x0 = 20.0 + v * 2.0
        for _kp in range(4):
            flat.extend([x0, 20.0 + 8.0 * _kp])
    _api, _err = recompute_from_flat_landmarks(flat, bgr)
    # Either Cobb succeeds (err None) or we get a string error; must not throw.
    assert _err is None or isinstance(_err, str)

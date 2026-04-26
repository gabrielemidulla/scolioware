"""Decode DICOM uploads to JPEG for the Keypoint-RCNN pipeline. Metadata is returned for storage only."""

from __future__ import annotations

import io
from typing import Any

import numpy as np
from PIL import Image


def _dicom_to_metadata(ds: Any) -> dict[str, Any]:
    out: dict[str, Any] = {}
    for tag, name in (
        ("StudyInstanceUID", "study_instance_uid"),
        ("SeriesInstanceUID", "series_instance_uid"),
        ("SOPInstanceUID", "sop_instance_uid"),
        ("Modality", "modality"),
        ("PatientID", "patient_id"),
    ):
        if hasattr(ds, tag):
            v = getattr(ds, tag, None)
            if v is not None and str(v) != "":
                out[name] = str(v)
    try:
        ps = ds.get((0x0028, 0x0030), None)
        if ps is not None and getattr(ps, "value", None) is not None:
            val = ps.value
            if isinstance(val, (list, tuple)) and val:
                out["pixel_spacing_mm"] = [float(x) for x in val]
            else:
                out["pixel_spacing_mm"] = [float(val), float(val)]
    except (TypeError, ValueError, AttributeError):
        pass
    return out


def dicom_bytes_to_jpeg(body: bytes) -> tuple[bytes, dict[str, Any]]:
    import pydicom

    ds = pydicom.dcmread(io.BytesIO(body), force=True)
    meta = _dicom_to_metadata(ds)

    arr = np.asarray(ds.pixel_array, dtype=np.float32)
    if arr is None or arr.size == 0:
        raise ValueError("DICOM has no displayable pixel data.")
    if (
        arr.ndim == 3
        and arr.shape[0] not in (1, 3, 4)
        and arr.shape[0] < min(arr.shape[1], arr.shape[2])
    ):
        z = int(arr.shape[0] // 2)
        arr = arr[z, :, :].astype(np.float32, copy=False)
    if arr.ndim == 2:
        arr3 = arr
    elif arr.ndim == 3:
        s = min(arr.shape[0], 4)
        if s >= 3 and arr.shape[0] in (3, 4) and arr.shape[0] < min(arr.shape[1], arr.shape[2]):
            # CHW
            sl = arr[:3, :, :]
            arr3 = np.transpose(sl, (1, 2, 0)) if sl.shape[0] in (1, 3) else sl[0, :, :]
        else:
            arr3 = arr[..., 0] if arr.shape[2] >= 1 else arr[0, :, :]
    else:
        raise ValueError("Unsupported DICOM pixel array shape")

    a = arr3
    a_min = float(np.min(a)) if a.size else 0.0
    a_max = float(np.max(a)) if a.size else 1.0
    if a_max - a_min < 1e-6:
        a2 = (a - a_min).astype(np.uint8)
    else:
        a2 = ((a - a_min) / (a_max - a_min) * 255.0).clip(0, 255).astype(np.uint8)
    if a2.ndim == 2:
        rgb = np.stack([a2, a2, a2], axis=-1)
    else:
        rgb = a2[:, :, :3] if a2.shape[2] >= 3 else np.repeat(a2[:, :, :1], 3, axis=2)

    pil = Image.fromarray(rgb, mode="RGB")
    out = io.BytesIO()
    pil.save(out, format="JPEG", quality=92)
    meta["decoder"] = "dicom_ingest_v1"
    return out.getvalue(), meta


def decode_upload_to_jpeg(body: bytes, filename: str) -> tuple[bytes, dict[str, Any]]:
    if not body:
        raise ValueError("Empty file.")
    name = (filename or "").lower()
    if name.endswith((".dcm", ".dicom")):
        return dicom_bytes_to_jpeg(body)
    # Preamble check (DICOM without extension)
    if len(body) > 132 and body[128:132] == b"DICM":
        return dicom_bytes_to_jpeg(body)
    from PIL import Image

    im = Image.open(io.BytesIO(body))
    im = im.convert("RGB")
    o = io.BytesIO()
    im.save(o, format="JPEG", quality=92)
    return o.getvalue(), {}

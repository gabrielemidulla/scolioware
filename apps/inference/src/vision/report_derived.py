"""Build report columns and ``report_metadata_json`` from inference API output."""

from __future__ import annotations

import json
from typing import Any

from src.vision.curve_type import normalize_curve_type


def _angle_deg(angles: dict | None, key: str) -> float | None:
    if not angles:
        return None
    block = angles.get(key)
    if not block or "angle" not in block:
        return None
    return float(block["angle"])


def _max_cobb(
    angles: dict,
) -> tuple[str | None, float | None, int | None, int | None]:
    """Return (region pt|mt|tl, degrees, superior_vert_1based, inferior_vert_1based)."""
    triple: list[tuple[str, float]] = []
    for k in ("pt", "mt", "tl"):
        v = _angle_deg(angles, k)
        if v is not None:
            triple.append((k, v))
    if not triple:
        return None, None, None, None
    region, deg = max(triple, key=lambda x: x[1])
    idxs = angles.get(region, {}).get("idxs")
    if not idxs or len(idxs) < 2:
        return region, deg, None, None
    sup = int(idxs[0]) + 1
    inf = int(idxs[1]) + 1
    return region, float(deg), sup, inf


def build_report_derived(api: dict[str, Any], image_shape: tuple[int, ...]) -> dict[str, Any]:
    """`image_shape` is (H, W, C). Returns DB-shaped fields and `report_metadata_json`."""
    h, w = int(image_shape[0]), int(image_shape[1])
    angles = api.get("angles")
    detections = api.get("detections") or []

    meta: dict[str, Any] = {
        "image": {"width": w, "height": h},
        "num_detections": len(detections),
        "software": "Scolioware",
        "curve_type": normalize_curve_type(api.get("curve_type")),
    }

    row: dict[str, Any] = {
        "image_width": w,
        "image_height": h,
        "cobb_pt_deg": None,
        "cobb_mt_deg": None,
        "cobb_tl_deg": None,
        "cobb_thoracic_deg": None,
        "cobb_lumbar_deg": None,
        "cobb_max_region": None,
        "cobb_max_deg": None,
        "cobb_max_vert_superior": None,
        "cobb_max_vert_inferior": None,
        "report_metadata_json": None,
    }

    if not angles:
        meta["angles_available"] = False
        row["report_metadata_json"] = json.dumps(meta)
        return row

    pt = _angle_deg(angles, "pt")
    mt = _angle_deg(angles, "mt")
    tl = _angle_deg(angles, "tl")
    row["cobb_pt_deg"] = pt
    row["cobb_mt_deg"] = mt
    row["cobb_tl_deg"] = tl

    thoracic: float | None = None
    if pt is not None and mt is not None:
        thoracic = max(pt, mt)
    elif pt is not None:
        thoracic = pt
    elif mt is not None:
        thoracic = mt
    row["cobb_thoracic_deg"] = thoracic
    row["cobb_lumbar_deg"] = tl

    mreg, mdeg, msup, minf = _max_cobb(angles)
    row["cobb_max_region"] = mreg
    row["cobb_max_deg"] = mdeg
    row["cobb_max_vert_superior"] = msup
    row["cobb_max_vert_inferior"] = minf

    meta["angles_available"] = True
    meta["pdf_equivalent"] = {
        "proximal_thoracic_degrees": pt,
        "main_thoracic_degrees": mt,
        "thoracolumbar_lumbar_degrees": tl,
        "thoracic_summary_degrees": thoracic,
        "lumbar_summary_degrees": tl,
        "greatest_cobb": {
            "region": mreg,
            "degrees": mdeg,
            "vertebra_superior": msup,
            "vertebra_inferior": minf,
        },
    }

    row["report_metadata_json"] = json.dumps(meta, default=str)
    return row

"""X-ray draft impression prompts and vision-side image prep."""

from __future__ import annotations

import io
from typing import Any

from PIL import Image

PROMPT_VERSION = "xray-firstread.v2"

_MAX_EDGE_PX = 896
_JPEG_QUALITY = 85

_SYSTEM_PROMPT = (
    "You are a senior radiology resident drafting a short preliminary description of a "
    "spine X-ray for the treating physician. The image is from a scoliosis screening / "
    "follow-up workflow, typically a standing whole-spine AP or PA radiograph "
    "(occasionally a lateral). "
    "Write ONE coherent paragraph (5–7 sentences, ≤170 words) covering, in order:\n"
    "  1. Projection (AP / PA / lateral) and apparent body region / coverage "
    "(cervical, thoracic, lumbar, pelvis included or not).\n"
    "  2. Image quality and patient positioning — rotation, tilt, exposure, "
    "centering, motion or other artefacts.\n"
    "  3. Spinal alignment in the coronal plane: describe whether the spine "
    "appears straight or shows lateral curvature, and if curvature is present "
    "name the apparent pattern in plain words (single C-shaped curve, "
    "double / S-shaped curve, thoracic vs lumbar predominance, side of "
    "convexity if confidently identifiable). It is OK and expected to call out "
    "scoliosis when you see it — just describe it qualitatively.\n"
    "  4. Vertebral bodies, intervertebral disc spaces, pedicles, ribs and "
    "any other visible bony landmarks.\n"
    "  5. Any obviously notable findings (fractures, surgical hardware, "
    "transitional anatomy, lytic/sclerotic lesions, soft-tissue asymmetries) "
    "or a brief 'no other obvious abnormalities' if none.\n"
    "Use neutral, hedged radiology language ('appears', 'suggests', "
    "'no obvious', 'limited assessment given the projection'). "
    "Do NOT give Cobb-angle numbers, severity grades (mild/moderate/severe), "
    "or a definitive diagnosis — quantitative measurements come from a separate "
    "tool and are shown elsewhere in the report. "
    "Do NOT invent patient identifiers or numeric measurements. "
    "Return plain prose only — no headings, no bullet lists, no markdown."
)

_CURVE_HINTS: dict[str, str] = {
    "C": (
        "An automated geometric pipeline classified the spinal curvature as a "
        "single-curve (C-shaped) pattern. Use this only as a sanity check while "
        "writing your own qualitative description."
    ),
    "S": (
        "An automated geometric pipeline classified the spinal curvature as a "
        "double-curve (S-shaped) pattern. Use this only as a sanity check while "
        "writing your own qualitative description."
    ),
}


def downscale_for_vision(image_bytes: bytes) -> bytes:
    """Longest edge ≤_MAX_EDGE_PX, JPEG. On error returns input unchanged."""
    try:
        with Image.open(io.BytesIO(image_bytes)) as im:
            im.load()
            if im.mode not in ("RGB", "L"):
                im = im.convert("RGB")
            elif im.mode == "L":
                im = im.convert("RGB")

            w, h = im.size
            longest = max(w, h)
            if longest > _MAX_EDGE_PX:
                scale = _MAX_EDGE_PX / float(longest)
                new_w = max(1, int(round(w * scale)))
                new_h = max(1, int(round(h * scale)))
                im = im.resize((new_w, new_h), Image.LANCZOS)

            buf = io.BytesIO()
            im.save(buf, format="JPEG", quality=_JPEG_QUALITY, optimize=True)
            return buf.getvalue()
    except Exception:
        return image_bytes


def build_xray_description_messages(
    *,
    patient_age_years: int | None = None,
    patient_sex: str | None = None,
    curve_type: str | None = None,
) -> list[dict[str, Any]]:
    """Ollama `messages` without image (client attaches it). Optional age/sex and C/S hint."""
    parts: list[str] = ["Please describe this spine X-ray for the treating physician."]
    ctx: list[str] = []
    if isinstance(patient_age_years, int) and 0 <= patient_age_years <= 120:
        ctx.append(f"approx. age {patient_age_years} y")
    if isinstance(patient_sex, str):
        s = patient_sex.strip().lower()
        if s in ("m", "male"):
            ctx.append("male")
        elif s in ("f", "female"):
            ctx.append("female")
    if ctx:
        parts.append(f"Patient context: {', '.join(ctx)}.")

    if isinstance(curve_type, str):
        hint = _CURVE_HINTS.get(curve_type.strip().upper())
        if hint:
            parts.append(hint)

    user_content = " ".join(parts)
    return [
        {"role": "system", "content": _SYSTEM_PROMPT},
        {"role": "user", "content": user_content},
    ]

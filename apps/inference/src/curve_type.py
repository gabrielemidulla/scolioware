"""Curve type values aligned with MySQL ENUM('C','S') on reports.curve_type."""

from __future__ import annotations

from typing import Any


def normalize_curve_type(value: Any) -> str | None:
    """Return 'C', 'S', or None for anything else (matches DB ENUM)."""
    if value is None:
        return None
    s = str(value).strip().upper()
    if s in ("C", "S"):
        return s
    return None

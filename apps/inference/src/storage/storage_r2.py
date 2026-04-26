"""S3/R2 access via dashboard HTTP proxy (no boto3 in inference)."""

from __future__ import annotations

import os
from typing import Final
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

_OP_GET: Final = "get"
_OP_PUT: Final = "put"
_OP_DELETE: Final = "delete"


def r2_configured() -> bool:
    return bool(os.environ.get("DASHBOARD_STORAGE_URL", "").strip())


def object_key_original(patient_id: int, report_id: int) -> str:
    return f"patients/{patient_id}/reports/{report_id}/original.jpeg"


def object_key_computed(patient_id: int, report_id: int) -> str:
    return f"patients/{patient_id}/reports/{report_id}/computed.jpeg"


def _base_url() -> str:
    return os.environ["DASHBOARD_STORAGE_URL"].strip().rstrip("/")


def _blob_url(op: str, key: str) -> str:
    q = urlencode({"op": op, "key": key})
    return f"{_base_url()}/internal_r2_blob.php?{q}"


def _internal_headers(*, content_type: str | None = None) -> dict[str, str]:
    h: dict[str, str] = {}
    tok = os.environ.get("INFERENCE_INTERNAL_TOKEN", "").strip()
    if tok:
        h["X-Internal-Token"] = tok
    if content_type:
        h["Content-Type"] = content_type
    return h


def put_bytes(key: str, body: bytes, content_type: str = "application/octet-stream") -> None:
    if not r2_configured():
        raise RuntimeError("DASHBOARD_STORAGE_URL is not set.")
    url = _blob_url(_OP_PUT, key)
    req = Request(
        url,
        data=body,
        method="PUT",
        headers=_internal_headers(content_type=content_type),
    )
    try:
        with urlopen(req, timeout=300) as resp:
            if resp.status not in (200, 204):
                raise RuntimeError(f"PUT storage HTTP {resp.status}")
    except HTTPError as e:
        detail = e.read().decode("utf-8", errors="replace")[:2000]
        raise RuntimeError(f"PUT storage HTTP {e.code}: {detail}") from e
    except URLError as e:
        raise RuntimeError(f"PUT storage network error: {e!s}") from e


def get_bytes(key: str) -> bytes:
    if not r2_configured():
        raise RuntimeError("DASHBOARD_STORAGE_URL is not set.")
    url = _blob_url(_OP_GET, key)
    req = Request(url, method="GET", headers=_internal_headers())
    try:
        with urlopen(req, timeout=300) as resp:
            if resp.status != 200:
                raise RuntimeError(f"GET storage HTTP {resp.status}")
            return resp.read()
    except HTTPError as e:
        if e.code == 404:
            raise FileNotFoundError(key) from e
        detail = e.read().decode("utf-8", errors="replace")[:2000]
        raise RuntimeError(f"GET storage HTTP {e.code}: {detail}") from e
    except URLError as e:
        raise RuntimeError(f"GET storage network error: {e!s}") from e


def delete_object(key: str) -> None:
    k = (key or "").strip()
    if not k:
        return
    if not r2_configured():
        raise RuntimeError("DASHBOARD_STORAGE_URL is not set.")
    url = _blob_url(_OP_DELETE, k)
    req = Request(url, method="DELETE", headers=_internal_headers())
    try:
        with urlopen(req, timeout=120) as resp:
            if resp.status not in (200, 204):
                raise RuntimeError(f"DELETE storage HTTP {resp.status}")
    except HTTPError as e:
        if e.code == 404:
            return
        detail = e.read().decode("utf-8", errors="replace")[:2000]
        raise RuntimeError(f"DELETE storage HTTP {e.code}: {detail}") from e
    except URLError as e:
        raise RuntimeError(f"DELETE storage network error: {e!s}") from e

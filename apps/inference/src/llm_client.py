"""Synchronous Ollama `/api/chat` client."""

from __future__ import annotations

import base64
import json
import os
import re
import time
from dataclasses import dataclass
from typing import Any

import requests


def _ollama_base_url() -> str:
    return (os.environ.get("OLLAMA_INTERNAL_URL") or "http://ollama:11434").rstrip("/")


def default_model() -> str:
    return (os.environ.get("OLLAMA_LLM_MODEL") or "medgemma:4b").strip()


def default_timeout_s() -> float:
    raw = (os.environ.get("OLLAMA_LLM_TIMEOUT") or "").strip()
    try:
        v = float(raw)
        if v > 0:
            return v
    except (TypeError, ValueError):
        pass
    return 600.0


@dataclass(frozen=True)
class LlmResponse:
    text: str
    model_tag: str
    prompt_tokens: int | None
    completion_tokens: int | None
    latency_ms: int


class LlmError(RuntimeError):
    pass


def _to_b64(image_bytes: bytes) -> str:
    return base64.b64encode(image_bytes).decode("ascii")


def chat(
    *,
    messages: list[dict[str, Any]],
    image_bytes: bytes | None = None,
    model: str | None = None,
    temperature: float = 0.2,
    top_p: float = 0.9,
    num_predict: int = 220,
    num_ctx: int = 4096,
    timeout_s: float | None = None,
) -> LlmResponse:
    """POST /api/chat. Image goes on the last user message. Empty/error → LlmError."""
    mdl = (model or default_model()).strip()
    if not mdl:
        raise LlmError("OLLAMA_LLM_MODEL is not set.")
    if not messages:
        raise LlmError("messages must not be empty.")

    msgs: list[dict[str, Any]] = [dict(m) for m in messages]
    if image_bytes is not None:
        for i in range(len(msgs) - 1, -1, -1):
            if msgs[i].get("role") == "user":
                msgs[i] = {**msgs[i], "images": [_to_b64(image_bytes)]}
                break
        else:
            raise LlmError("image_bytes provided but no user message to attach it to.")

    payload: dict[str, Any] = {
        "model": mdl,
        "messages": msgs,
        "stream": False,
        "keep_alive": "24h",
        "options": {
            "temperature": float(temperature),
            "top_p": float(top_p),
            "num_predict": int(num_predict),
            "num_ctx": int(num_ctx),
        },
    }

    url = _ollama_base_url() + "/api/chat"
    t = timeout_s if (timeout_s and timeout_s > 0) else default_timeout_s()

    started = time.monotonic()
    try:
        r = requests.post(url, json=payload, timeout=t)
    except requests.RequestException as e:
        raise LlmError(f"Ollama request failed: {e}") from e
    latency_ms = int((time.monotonic() - started) * 1000)

    if r.status_code < 200 or r.status_code >= 300:
        err = _ollama_api_error(mdl, r.status_code, r.text or "")
        raise LlmError(err)

    try:
        data = r.json()
    except ValueError as e:
        raise LlmError("Ollama returned non-JSON body.") from e

    msg = data.get("message") or {}
    text = (msg.get("content") or "").strip()
    if not text:
        raise LlmError("Ollama returned an empty completion.")

    return LlmResponse(
        text=text,
        model_tag=str(data.get("model") or mdl),
        prompt_tokens=_int_or_none(data.get("prompt_eval_count")),
        completion_tokens=_int_or_none(data.get("eval_count")),
        latency_ms=latency_ms,
    )


def _int_or_none(v: Any) -> int | None:
    if v is None:
        return None
    try:
        return int(v)
    except (TypeError, ValueError):
        return None


def _ollama_api_error(mdl: str, status: int, body: str) -> str:
    """User-facing string; 404 missing model gets a `ollama pull` hint when detectable."""
    raw = (body or "").strip()
    if status == 404 and mdl and _looks_like_model_not_found(mdl, raw):
        ollama_url = _ollama_base_url()
        is_host_ollama = re.search(
            r"host\.docker\.internal|192\.168\.(65|5)\.2", ollama_url, re.IGNORECASE
        )
        hint = (
            f"Model {mdl!r} is not in this Ollama's library. Pull it: `ollama pull {mdl}` on the same "
            f"machine that is serving {ollama_url}. "
        )
        if is_host_ollama:
            hint += (
                "MPS: Ollama runs on the host (not in Docker), so run that command on your Mac once; "
                "see also `scripts/mps/ollama-pull-llm-model.sh` in the repo. "
            )
        hint += f"Original 404: {raw[:200]}"
        return hint
    return f"Ollama HTTP {status}: {raw[:300]}"


def _looks_like_model_not_found(mdl: str, raw: str) -> bool:
    s = raw.lower()
    if "not found" not in s and "file not found" not in s:
        return False
    try:
        o = json.loads(raw)
        err = str((o or {}).get("error") or "").lower()
    except (TypeError, ValueError):
        err = s
    if "model" in err and "not" in err:
        return mdl in raw or mdl in err or mdl.split(":")[0] in err
    return mdl in raw

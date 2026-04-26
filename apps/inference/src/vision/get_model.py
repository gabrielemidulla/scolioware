from __future__ import annotations

import os
import shutil
from pathlib import Path

import structlog
import torch

from src.spine_net import SpineNet

log = structlog.get_logger("get_model")

DEFAULT_MODEL_FILENAME = "spine_net_weights.pth"

HEADS = {"hm": 1, "reg": 2, "wh": 2 * 4}
DOWN_RATIO = 4
INPUT_H = 1024
INPUT_W = 512
NUM_VERTEBRAE = 17


def model_dir() -> Path:
    return Path(os.environ.get("MODEL_DIR", "models"))


def model_filename() -> str:
    return (os.environ.get("MODEL_FILENAME") or DEFAULT_MODEL_FILENAME).strip() or DEFAULT_MODEL_FILENAME


def weights_path() -> Path:
    return model_dir() / model_filename()


def _hf_repo_id() -> str | None:
    raw = (os.environ.get("HF_REPO_ID") or "").strip()
    return raw or None


def _hf_filename() -> str:
    return (os.environ.get("HF_FILENAME") or "").strip() or model_filename()


def _hf_revision() -> str:
    return (os.environ.get("HF_REVISION") or "main").strip() or "main"


def _hf_token() -> str | None:
    raw = (os.environ.get("HF_TOKEN") or os.environ.get("HUGGING_FACE_HUB_TOKEN") or "").strip()
    return raw or None


def _download_from_huggingface(dest: Path) -> None:
    from huggingface_hub import hf_hub_download
    from huggingface_hub.utils import (
        EntryNotFoundError,
        GatedRepoError,
        RepositoryNotFoundError,
        RevisionNotFoundError,
    )

    repo_id = _hf_repo_id()
    if not repo_id:
        raise RuntimeError("HF_REPO_ID must be set to download SpineNet weights from Hugging Face.")
    filename = _hf_filename()
    revision = _hf_revision()
    token = _hf_token()

    log.info(
        "spine_net_weights_hf_download_start",
        repo_id=repo_id,
        filename=filename,
        revision=revision,
        dest=str(dest),
        authenticated=bool(token),
    )

    try:
        cached = hf_hub_download(
            repo_id=repo_id,
            filename=filename,
            revision=revision,
            token=token,
            cache_dir=str(dest.parent / ".hf_cache"),
            repo_type="model",
        )
    except RepositoryNotFoundError as e:
        raise RuntimeError(
            f"Hugging Face repo {repo_id!r} was not found, or HF_TOKEN does not grant access."
        ) from e
    except GatedRepoError as e:
        raise RuntimeError(
            f"Hugging Face repo {repo_id!r} is gated; HF_TOKEN must accept the model license."
        ) from e
    except RevisionNotFoundError as e:
        raise RuntimeError(
            f"Hugging Face repo {repo_id!r} has no revision {revision!r}."
        ) from e
    except EntryNotFoundError as e:
        raise RuntimeError(
            f"File {filename!r} not found in Hugging Face repo {repo_id!r} at {revision!r}."
        ) from e

    cached_path = Path(cached)
    if cached_path.resolve() != dest.resolve():
        shutil.copyfile(cached_path, dest)

    log.info(
        "spine_net_weights_hf_download_done",
        repo_id=repo_id,
        filename=filename,
        revision=revision,
        dest=str(dest),
    )


def ensure_model_weights() -> Path:
    d = model_dir()
    d.mkdir(parents=True, exist_ok=True)

    canonical = weights_path()
    if canonical.is_file():
        log.info("spine_net_weights_present", path=str(canonical))
        return canonical

    log.info("spine_net_weights_missing", path=str(canonical))
    if _hf_repo_id():
        _download_from_huggingface(canonical)
        return canonical

    raise RuntimeError(
        f"Missing {model_filename()}. Mount ./apps/inference/models with the file inside, "
        "or set HF_REPO_ID (and HF_TOKEN if the repo is private) in the environment."
    )


def _load_state_dict(path: Path) -> dict:
    try:
        ckpt = torch.load(path, map_location=torch.device("cpu"), weights_only=True)
    except (TypeError, RuntimeError):
        ckpt = torch.load(path, map_location=torch.device("cpu"))
    if isinstance(ckpt, dict) and "state_dict" in ckpt and isinstance(ckpt["state_dict"], dict):
        return ckpt["state_dict"]
    return ckpt


def get_spine_net_model() -> SpineNet:
    path = ensure_model_weights()
    model = SpineNet(heads=HEADS, down_ratio=DOWN_RATIO, final_kernel=1, head_conv=256)
    state_dict = _load_state_dict(path)
    missing, unexpected = model.load_state_dict(state_dict, strict=False)
    if missing or unexpected:
        log.warning(
            "spine_net_load_state_dict_mismatch",
            missing_count=len(missing),
            unexpected_count=len(unexpected),
            first_missing=list(missing)[:3],
            first_unexpected=list(unexpected)[:3],
        )
    return model

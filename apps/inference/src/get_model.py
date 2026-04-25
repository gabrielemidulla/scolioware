import os
from pathlib import Path

import torch
from torchvision.models.detection.rpn import AnchorGenerator
import torchvision

MODEL_FILENAME = "keypointsrcnn_weights.pt"


def model_dir() -> Path:
    return Path(os.environ.get("MODEL_DIR", "models"))


def weights_path() -> Path:
    return model_dir() / MODEL_FILENAME


def _download_from_url(url: str, dest: Path) -> None:
    import requests

    print(f"Downloading weights from URL → {dest} ...")
    with requests.get(url, stream=True, timeout=600) as r:
        r.raise_for_status()
        with open(dest, "wb") as f:
            for chunk in r.iter_content(chunk_size=1024 * 1024):
                if chunk:
                    f.write(chunk)
    print("Weights download finished.")


def _download_kprcnn_model_deta(dest: Path) -> None:
    from deta import Deta

    print("DETA: Downloading Keypoint RCNN model...")
    deta = Deta(os.environ["DETA_ID"])
    models = deta.Drive("models")
    model_file = models.get(MODEL_FILENAME)
    with open(dest, "wb") as f:
        for chunk in model_file.iter_chunks(1024):
            f.write(chunk)
    model_file.close()
    print("DETA: Keypoint RCNN model downloaded.")


def ensure_model_weights() -> None:
    """Download model weights if missing (idempotent)."""
    d = model_dir()
    d.mkdir(parents=True, exist_ok=True)
    path = weights_path()
    if path.is_file():
        print(f"Keypoint RCNN weights already present at {path}")
        return

    print(f"Keypoint RCNN weights not found at {path}")
    url = os.environ.get("MODEL_DOWNLOAD_URL", "").strip()
    if url:
        _download_from_url(url, path)
        return

    deta_id = os.environ.get("DETA_ID", "").strip()
    if deta_id:
        _download_kprcnn_model_deta(path)
        return

    raise RuntimeError(
        "Missing keypointsrcnn_weights.pt. Mount ./apps/inference/models with the file inside, "
        "or set MODEL_DOWNLOAD_URL or DETA_ID in the environment."
    )


def get_kprcnn_model():
    ensure_model_weights()
    path = weights_path()

    num_keypoints = 4
    anchor_generator = AnchorGenerator(
        sizes=(32, 64, 128, 256, 512),
        aspect_ratios=(0.25, 0.5, 0.75, 1.0, 2.0, 3.0, 4.0),
    )
    model = torchvision.models.detection.keypointrcnn_resnet50_fpn(
        pretrained=False,
        pretrained_backbone=True,
        num_keypoints=num_keypoints,
        num_classes=2,
        rpn_anchor_generator=anchor_generator,
    )
    state_dict = torch.load(path, map_location=torch.device("cpu"))
    model.load_state_dict(state_dict)

    return model

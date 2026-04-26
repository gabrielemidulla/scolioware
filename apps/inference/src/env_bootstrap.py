from __future__ import annotations

from pathlib import Path

import dotenv


def load_dotenv_files() -> None:
    inference_root = Path(__file__).resolve().parent.parent
    candidates = [inference_root / ".env"]
    if inference_root.name == "inference" and inference_root.parent.name == "apps":
        candidates.append(inference_root.parent.parent / ".env")
    for p in candidates:
        if p.is_file():
            dotenv.load_dotenv(p)
    dotenv.load_dotenv()


load_dotenv_files()

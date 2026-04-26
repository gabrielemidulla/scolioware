#!/bin/sh
set -e
cd /app
python -c "from src.vision.get_model import ensure_model_weights; ensure_model_weights()"
exec uvicorn main:app --host 0.0.0.0 --port 8000

FROM python:3.11-slim-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    libglib2.0-0 libsm6 libxext6 libxrender1 libgomp1 libgl1 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY apps/inference/requirements.txt .
RUN pip install --upgrade pip \
    && pip install torch torchvision --index-url https://download.pytorch.org/whl/cpu \
    && pip install -r requirements.txt

COPY apps/inference/main.py .
COPY apps/inference/worker_main.py .
COPY apps/inference/worker_llm_main.py .
COPY infra/docker/inference/docker-entrypoint.sh ./docker-entrypoint.sh
COPY apps/inference/src/ ./src/

RUN chmod +x docker-entrypoint.sh

ENV MODEL_DIR=/app/models
ENV PYTHONPATH=/app

EXPOSE 8000

ENTRYPOINT ["./docker-entrypoint.sh"]

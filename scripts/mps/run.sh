#!/usr/bin/env bash
set -euo pipefail
# LLM: Ollama runs on the macOS host (brew install ollama && ollama serve) with Metal.
# In-compose Ollama is NOT started — the Docker ollama-init that pulls the model does not run.
# One-time, from the host (before the first "Generate" in the app):
#   ./scripts/mps/ollama-pull-llm-model.sh
# Optional: MPS_OLLAMA_URL=... to point at a non-default host:port.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=../lib/compose-env.sh
source "$REPO_ROOT/scripts/lib/compose-env.sh"
sv_compose_set_mps
cd "$SV_ROOT" || exit 1
exec docker compose -f docker-compose.yaml up -d "$@"

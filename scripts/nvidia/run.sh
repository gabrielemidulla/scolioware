#!/usr/bin/env bash
set -euo pipefail
# LLM: in-compose Ollama, NVIDIA GPU (Linux + nvidia-container-toolkit). ~5–20 s per draft.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=../lib/compose-env.sh
source "$REPO_ROOT/scripts/lib/compose-env.sh"
sw_compose_set_nvidia
cd "$SW_ROOT" || exit 1
exec docker compose -f docker-compose.yaml up -d "$@"

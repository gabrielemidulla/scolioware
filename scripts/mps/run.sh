#!/usr/bin/env bash
set -euo pipefail
# Uses host Ollama (not the compose ollama-* services). Model: scripts/mps/ollama-pull-llm-model.sh
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=../lib/compose-env.sh
source "$REPO_ROOT/scripts/lib/compose-env.sh"
sw_compose_set_mps
cd "$SW_ROOT" || exit 1
exec docker compose -f docker-compose.yaml up -d "$@"

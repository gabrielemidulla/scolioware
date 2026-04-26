#!/usr/bin/env bash
set -euo pipefail
# Same as run.sh. Optional: MPS_OLLAMA_URL=... to override the host Ollama base URL.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=../lib/compose-env.sh
source "$REPO_ROOT/scripts/lib/compose-env.sh"
sv_compose_set_mps
cd "$SV_ROOT" || exit 1
exec docker compose -f docker-compose.yaml build "$@"

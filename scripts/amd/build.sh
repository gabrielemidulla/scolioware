#!/usr/bin/env bash
set -euo pipefail
# Same as run.sh.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=../lib/compose-env.sh
source "$REPO_ROOT/scripts/lib/compose-env.sh"
sw_compose_set_amd
cd "$SW_ROOT" || exit 1
exec docker compose -f docker-compose.yaml build "$@"

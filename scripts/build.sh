#!/usr/bin/env bash
set -euo pipefail
# Same autodetection as run.sh; delegates to scripts/{mps|nvidia|amd|cpu}/build.sh
# Override: SV_LLM_TARGET=cpu|nvidia|amd|mps

_SCRIPTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/detect-llm-target.sh
source "$_SCRIPTS_DIR/lib/detect-llm-target.sh"

_target="$(sv_llm_target_print)" || exit 1
if [[ -z "${SV_LLM_TARGET:-}" ]] && [[ -t 2 ]]; then
  echo "LLM / compose profile: $_target  (set SV_LLM_TARGET=cpu|nvidia|amd|mps to override; see scripts/lib/detect-llm-target.sh)" >&2
fi
_child="$_SCRIPTS_DIR/$_target/build.sh"
if [[ ! -f "$_child" ]]; then
  echo "build.sh: missing $_child" >&2
  exit 1
fi
exec "$_child" "$@"

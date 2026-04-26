#!/usr/bin/env bash
set -euo pipefail
# Pull the LLM used by the dashboard (default medgemma:4b) into the *local* Ollama
# store. Run this on the macOS host when you use MPS: there is no docker ollama-init
# in that path, so the model is never downloaded for you.
#
# Usage:
#   ./scripts/mps/ollama-pull-llm-model.sh
#   ./scripts/mps/ollama-pull-llm-model.sh medgemma:4b
#
# Picks OLLAMA_LLM_MODEL from the environment, or from infra/.env (same as compose).

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MDL="${OLLAMA_LLM_MODEL:-medgemma:4b}"
_env="$REPO_ROOT/infra/.env"
if [[ -f "$_env" ]]; then
  _line=$(grep -E '^[[:space:]]*OLLAMA_LLM_MODEL=' "$_env" | tail -1 || true)
  if [[ -n "${_line}" ]]; then
    _val="${_line#*=}"
    _val="${_val%$'\r'}"
    _val="${_val#\"}"
    _val="${_val%\"}"
    _val="${_val#\'}"
    _val="${_val%\'}"
    if [[ -n "$_val" ]]; then
      MDL="$_val"
    fi
  fi
  unset _line _val _env
fi
if [[ -n "${1:-}" ]]; then
  MDL="$1"
fi

if ! command -v ollama >/dev/null 2>&1; then
  echo "Ollama is not on your PATH. Install: https://ollama.com/ (macOS: brew install ollama)" >&2
  exit 1
fi

echo "Running: ollama pull $MDL"
exec ollama pull "$MDL"

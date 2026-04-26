# shellcheck shell=bash
# Sourced by scripts/{run,build}.sh (universal) and scripts/{cpu,nvidia,amd,mps}/{run,build}.sh. Do not execute directly.
# Sets COMPOSE_PROFILES and OLLAMA_INTERNAL_URL in the *current shell* for the
# `docker compose` process — no need to edit infra/.env for these two knobs.
#
# Precedence: variables exported here override a project .env for substitution
# in the compose file for this invocation. Keep secrets in infra/.env; only
# runtime routing (profile + Ollama URL) is controlled by these helpers.

# Repo root: this file is scripts/lib/compose-env.sh
if [[ -z ${SV_ROOT:-} ]]; then
  SV_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
  export SV_ROOT
fi

# In-container Ollama: alias `ollama` on the app network (see infra/docker-compose.yaml)
SV_OLLAMA_URL_IN_COMPOSE="http://ollama:11434"
# macOS: native Ollama on the host, reachable from Linux containers
SV_OLLAMA_URL_HOST="http://host.docker.internal:11434"

# CPU — Ollama in Docker, no GPU.
sv_compose_set_cpu() {
  export COMPOSE_PROFILES=cpu
  export OLLAMA_INTERNAL_URL="$SV_OLLAMA_URL_IN_COMPOSE"
}

# NVIDIA (Linux) — in-compose Ollama with GPU reservation.
sv_compose_set_nvidia() {
  export COMPOSE_PROFILES=gpu-nvidia
  export OLLAMA_INTERNAL_URL="$SV_OLLAMA_URL_IN_COMPOSE"
}

# AMD ROCm (Linux) — in-compose ollama/ollama:rocm.
sv_compose_set_amd() {
  export COMPOSE_PROFILES=gpu-amd
  export OLLAMA_INTERNAL_URL="$SV_OLLAMA_URL_IN_COMPOSE"
}

# macOS / Apple Silicon — Ollama runs natively (Metal/MPS); do not start in-compose Ollama.
# Optional: export MPS_OLLAMA_URL=... before sourcing if host.docker.internal is not suitable.
sv_compose_set_mps() {
  unset COMPOSE_PROFILES
  if [[ -n ${MPS_OLLAMA_URL:-} ]]; then
    export OLLAMA_INTERNAL_URL="$MPS_OLLAMA_URL"
  else
    export OLLAMA_INTERNAL_URL="$SV_OLLAMA_URL_HOST"
  fi
}

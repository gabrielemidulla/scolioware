# shellcheck shell=bash

if [[ -z ${SW_ROOT:-} ]]; then
  SW_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
  export SW_ROOT
fi

SW_OLLAMA_URL_IN_COMPOSE="http://ollama:11434"
SW_OLLAMA_URL_HOST="http://host.docker.internal:11434"

sw_compose_set_cpu() {
  export COMPOSE_PROFILES=cpu
  export OLLAMA_INTERNAL_URL="$SW_OLLAMA_URL_IN_COMPOSE"
}

sw_compose_set_nvidia() {
  export COMPOSE_PROFILES=gpu-nvidia
  export OLLAMA_INTERNAL_URL="$SW_OLLAMA_URL_IN_COMPOSE"
}

sw_compose_set_amd() {
  export COMPOSE_PROFILES=gpu-amd
  export OLLAMA_INTERNAL_URL="$SW_OLLAMA_URL_IN_COMPOSE"
}

# MPS: host Ollama (optional MPS_OLLAMA_URL).
sw_compose_set_mps() {
  unset COMPOSE_PROFILES
  if [[ -n ${MPS_OLLAMA_URL:-} ]]; then
    export OLLAMA_INTERNAL_URL="$MPS_OLLAMA_URL"
  else
    export OLLAMA_INTERNAL_URL="$SW_OLLAMA_URL_HOST"
  fi
}

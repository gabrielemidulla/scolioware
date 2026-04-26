# shellcheck shell=bash
# Prints one of: mps | nvidia | amd | cpu. Override with SW_LLM_TARGET=...

sw_llm_target_print() {
  if [[ -n "${SW_LLM_TARGET:-}" ]]; then
    case "$SW_LLM_TARGET" in
    cpu|nvidia|amd|mps) echo "$SW_LLM_TARGET" ;;
    *)
      echo "sw_llm_target: SW_LLM_TARGET must be one of: cpu, nvidia, amd, mps; got: ${SW_LLM_TARGET}" >&2
      return 1
      ;;
    esac
    return 0
  fi

  # Apple hosts: native Ollama (Metal) — Docker cannot pass the Apple GPU through.
  if [[ "$(uname -s)" == "Darwin" ]]; then
    echo mps
    return 0
  fi

  if [[ "$(uname -s)" == "Linux" ]]; then
    if _sw_has_nvidia; then
      echo nvidia
    elif _sw_has_amd_rocm; then
      echo amd
    else
      echo cpu
    fi
    return 0
  fi

  # Other (FreeBSD, MSYS, …): in-compose Ollama is CPU; skip GPU docker profiles.
  echo cpu
}

_sw_has_nvidia() {
  command -v nvidia-smi &>/dev/null && nvidia-smi -L &>/dev/null
}

# Standard ROCm / AMD: render driver exposes /dev/kfd. Matches our ollama:rocm compose.
_sw_has_amd_rocm() {
  [[ -e /dev/kfd ]]
}

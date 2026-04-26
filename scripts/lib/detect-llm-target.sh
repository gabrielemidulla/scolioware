# shellcheck shell=bash
# Used by ../run.sh and ../build.sh. Prints a single line: mps | nvidia | amd | cpu
#
# Optional override: SV_LLM_TARGET=cpu|nvidia|amd|mps (e.g. CI, or to force a profile)

sv_llm_target_print() {
  if [[ -n "${SV_LLM_TARGET:-}" ]]; then
    case "$SV_LLM_TARGET" in
    cpu|nvidia|amd|mps) echo "$SV_LLM_TARGET" ;;
    *)
      echo "sv_llm_target: SV_LLM_TARGET must be one of: cpu, nvidia, amd, mps; got: ${SV_LLM_TARGET}" >&2
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
    if _sv_has_nvidia; then
      echo nvidia
    elif _sv_has_amd_rocm; then
      echo amd
    else
      echo cpu
    fi
    return 0
  fi

  # Other (FreeBSD, MSYS, …): in-compose Ollama is CPU; skip GPU docker profiles.
  echo cpu
}

_sv_has_nvidia() {
  command -v nvidia-smi &>/dev/null && nvidia-smi -L &>/dev/null
}

# Standard ROCm / AMD: render driver exposes /dev/kfd. Matches our ollama:rocm compose.
_sv_has_amd_rocm() {
  [[ -e /dev/kfd ]]
}

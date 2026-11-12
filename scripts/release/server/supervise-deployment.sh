#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
directory=$(cd -- "$(dirname -- "$0")" && pwd -P)
[[ ! -L $directory/diagnostics && ! -e $directory/diagnostics ]] || { echo 'Diagnostics already exist; refusing reuse.' >&2; exit 1; }
mkdir -m 700 -- "$directory/diagnostics"
export FAMIE_DEPLOY_DIAGNOSTICS_DIR="$directory/diagnostics"
source "$directory/deployment-diagnostics.sh"
diagnostic_init supervisor
finish() {
  local result=$?
  trap - EXIT
  diagnostic_record finished "$result" "$LINENO" || true
  exit "$result"
}
trap finish EXIT
trap 'diagnostic_interrupted HUP 129 "$LINENO"' HUP
trap 'diagnostic_interrupted INT 130 "$LINENO"' INT
trap 'diagnostic_interrupted TERM 143 "$LINENO"' TERM
diagnostic_checkpoint child_starting
result=0
"$directory/deploy-artifact.sh" "$@" </dev/null || result=$?
diagnostic_checkpoint child_finished "$result"
exit "$result"

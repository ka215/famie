#!/usr/bin/env bash
# Structured metadata only: never record commands, arguments or environment values.
diagnostic_init() {
  diagnostic_component=$1
  diagnostic_stage=initializing
  diagnostic_signal=none
  [[ $diagnostic_component =~ ^[a-z_]+$ ]] || return 1
  [[ -d $FAMIE_DEPLOY_DIAGNOSTICS_DIR && ! -L $FAMIE_DEPLOY_DIAGNOSTICS_DIR ]] || return 1
  diagnostic_file="$FAMIE_DEPLOY_DIAGNOSTICS_DIR/$diagnostic_component.log"
  [[ ! -e $diagnostic_file && ! -L $diagnostic_file ]] || return 1
  (set -o noclobber; : > "$diagnostic_file") || return 1
  chmod 600 "$diagnostic_file" || return 1
  diagnostic_record started 0 0
}

diagnostic_record() {
  local event=$1 code=${2:-0} line=${3:-0} at record
  [[ $event =~ ^[a-z_]+$ && $code =~ ^[0-9]+$ && $line =~ ^[0-9]+$ && $diagnostic_stage =~ ^[a-z_]+$ ]] || return 1
  printf -v at '%(%Y-%m-%dT%H:%M:%S%z)T' -1
  printf -v record 'at=%s component=%s event=%s stage=%s pid=%s ppid=%s exit=%s line=%s signal=%s' \
    "$at" "$diagnostic_component" "$event" "$diagnostic_stage" "$BASHPID" "$PPID" "$code" "$line" "$diagnostic_signal"
  printf '%s\n' "$record" >> "$diagnostic_file" || return 1
  printf '%s\n' "$record" >&2
}

diagnostic_checkpoint() {
  diagnostic_stage=$1
  diagnostic_record checkpoint "${2:-0}" "${3:-0}"
}

diagnostic_interrupted() {
  diagnostic_signal=$1
  diagnostic_record interrupted "$2" "$3" || true
  exit "$2"
}

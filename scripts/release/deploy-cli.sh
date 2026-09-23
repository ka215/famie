#!/usr/bin/env bash
# Compatibility entry point only. Keep the standard script beside this file.
set -Eeuo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
script_name=$(basename "${BASH_SOURCE[0]}")
standard_script="$script_dir/${script_name%-cli.sh}.sh"
[[ -f $standard_script ]] || {
  echo "ERROR: install the standard deployment script at $standard_script" >&2
  exit 1
}
if [[ ${BASH_SOURCE[0]} != "$0" ]]; then
  source "$standard_script"
  return 0
fi
echo "Deprecated entry point; forwarding to $standard_script" >&2
exec bash "$standard_script" "$@"

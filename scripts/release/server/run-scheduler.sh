#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

fail() { printf 'Famie scheduler diagnostic failed: %s\n' "$1" >&2; exit 1; }
[[ $# == 1 ]] || fail 'Specify staging or production.'
environment=$1
case $environment in
  staging) app_name=famie-stg ;;
  production) app_name=famie ;;
  *) fail 'Unknown environment.' ;;
esac
for tool in realpath date chmod mkdir stat find touch jq; do
  command -v "$tool" >/dev/null || fail 'Required system tool unavailable.'
done
home=$(realpath -- "$HOME")
backend="$home/$app_name/backend"
[[ -d $backend && ! -L $backend && -f $backend/artisan ]] || fail 'Backend unavailable.'
[[ $(realpath -- "$backend") == "$backend" ]] || fail 'Backend must not resolve through a symlink.'
directory="$backend/storage/app/operations"
[[ ! -L $backend/storage && ! -L $backend/storage/app && ! -L $directory ]] || fail 'Diagnostic path must not be a symlink.'
mkdir -p -- "$directory"
[[ $(realpath -- "$directory") == "$directory" ]] || fail 'Unexpected diagnostic directory.'
chmod 700 -- "$directory"
log="$directory/scheduler-$(date -u +%F).jsonl"
[[ ! -L $log && ! -d $log ]] || fail 'Invalid diagnostic file.'
touch -- "$log"
chmod 600 -- "$log"
[[ $(stat -c %s -- "$log") -lt 2097152 ]] || fail 'Daily diagnostic size limit reached.'

started=$(date +%s)
run_id="$started-$$"
maintenance=false
[[ ! -f $backend/storage/framework/down ]] || maintenance=true
record() {
  jq -cn --arg at "$(date -u +%FT%TZ)" --arg environment "$environment" \
    --arg run_id "$run_id" --arg phase "$1" --argjson maintenance "$maintenance" \
    --argjson exit_code "$2" --argjson elapsed_seconds "$3" \
    '{at:$at,environment:$environment,run_id:$run_id,phase:$phase,maintenance:$maintenance,exit_code:$exit_code,elapsed_seconds:$elapsed_seconds}' >> "$log" \
    || fail 'Cannot write diagnostic record.'
}
record started null 0
finish() {
  result=$?
  trap - EXIT
  record finished "$result" "$(( $(date +%s) - started ))"
}
trap finish EXIT

php_cli=${PHPCLI:-/usr/local/bin/php84cli}
timeout_seconds=${FAMIE_SCHEDULER_TIMEOUT_SECONDS:-900}
[[ $timeout_seconds =~ ^[1-9][0-9]{0,2}$ && $timeout_seconds -le 900 ]] || fail 'Invalid scheduler timeout.'
[[ -x $php_cli && -x /usr/bin/timeout ]] || fail 'PHP CLI or timeout unavailable.'
cd -- "$backend"
status=0
/usr/bin/timeout --kill-after=10 "$timeout_seconds" "$php_cli" artisan schedule:run --no-interaction >/dev/null 2>&1 || status=$?
# Keep only this wrapper's regular history files; never follow symlinks or prune application logs.
find "$directory" -maxdepth 1 -type f -name 'scheduler-????-??-??.jsonl' -mtime +30 -delete \
  || fail 'Cannot prune diagnostic history.'
exit "$status"

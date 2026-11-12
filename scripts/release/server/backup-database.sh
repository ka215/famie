#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
[[ $# == 2 ]] || fail 'Usage: backup-database.sh BACKEND_DIRECTORY BACKUP_DIRECTORY'
backend=$(realpath "$1")
backup_dir=$2
PHPCLI=${PHPCLI:-/usr/local/bin/php84cli}
for tool in jq pg_dump pg_restore sha256sum realpath; do command -v "$tool" >/dev/null || fail "Missing tool: $tool"; done
[[ -x $PHPCLI && -f $backend/artisan && -f $backend/.env ]] || fail 'Invalid backend or PHP CLI.'
mkdir -p "$backup_dir"
backup_dir=$(realpath "$backup_dir")
if [[ -z ${FAMIE_DEPLOY_DIAGNOSTICS_DIR:-} ]]; then
  FAMIE_DEPLOY_DIAGNOSTICS_DIR=$(mktemp -d "$backup_dir/diagnostics.XXXXXX")
fi
source "$(dirname "$0")/deployment-diagnostics.sh"
diagnostic_init backup
finish() {
  local result=$?
  trap - EXIT ERR
  diagnostic_record finished "$result" "$LINENO" || true
  exit "$result"
}
trap finish EXIT
trap 'diagnostic_record error "$?" "$LINENO"' ERR
trap 'diagnostic_interrupted HUP 129 "$LINENO"' HUP
trap 'diagnostic_interrupted INT 130 "$LINENO"' INT
trap 'diagnostic_interrupted TERM 143 "$LINENO"' TERM
diagnostic_checkpoint connection_configuration

connection=$(
  cd "$backend"
  "$PHPCLI" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo json_encode(config("database.connections.pgsql"), JSON_THROW_ON_ERROR);' 2>/dev/null
)
host=$(jq -er '.host' <<< "$connection")
port=$(jq -er '.port|tostring' <<< "$connection")
database=$(jq -er '.database' <<< "$connection")
username=$(jq -er '.username' <<< "$connection")
password=$(jq -er '.password // ""' <<< "$connection")
file="$backup_dir/${database}-$(date +%Y%m%d-%H%M%S).dump"
diagnostic_checkpoint dump_starting
PGPASSWORD=$password pg_dump --host "$host" --port "$port" --username "$username" --format=custom --file "$file" "$database" 2>/dev/null
diagnostic_checkpoint dump_finished
[[ -s $file ]] || fail 'Database backup is empty.'
chmod 600 "$file"
diagnostic_checkpoint archive_list_starting
pg_restore --list "$file" > "$file.list" 2>/dev/null
diagnostic_checkpoint archive_list_finished
grep -Eq 'TABLE DATA .* users( |$)' "$file.list" || fail 'Backup does not contain users table data.'
grep -Eq 'TABLE DATA .* activity_logs( |$)' "$file.list" || fail 'Backup does not contain activity_logs table data.'
diagnostic_checkpoint checksum_starting
sha256sum "$file" > "$file.sha256"
diagnostic_checkpoint backup_complete
printf '%s\n' "$file"

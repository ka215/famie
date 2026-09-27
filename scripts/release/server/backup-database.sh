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

connection=$(
  cd "$backend"
  "$PHPCLI" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo json_encode(config("database.connections.pgsql"), JSON_THROW_ON_ERROR);'
)
host=$(jq -er '.host' <<< "$connection")
port=$(jq -er '.port|tostring' <<< "$connection")
database=$(jq -er '.database' <<< "$connection")
username=$(jq -er '.username' <<< "$connection")
password=$(jq -er '.password // ""' <<< "$connection")
file="$backup_dir/${database}-$(date +%Y%m%d-%H%M%S).dump"
PGPASSWORD=$password pg_dump --host "$host" --port "$port" --username "$username" --format=custom --file "$file" "$database"
[[ -s $file ]] || fail 'Database backup is empty.'
chmod 600 "$file"
pg_restore --list "$file" > "$file.list"
grep -Eq 'TABLE DATA .* users( |$)' "$file.list" || fail 'Backup does not contain users table data.'
grep -Eq 'TABLE DATA .* activity_logs( |$)' "$file.list" || fail 'Backup does not contain activity_logs table data.'
sha256sum "$file" > "$file.sha256"
printf '%s\n' "$file"

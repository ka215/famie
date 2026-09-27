#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
[[ $# == 2 ]] || fail 'Usage: restore-database.sh BACKEND_DIRECTORY BACKUP_FILE'
backend=$(realpath "$1")
backup=$(realpath "$2")
PHPCLI=${PHPCLI:-/usr/local/bin/php84cli}
for tool in jq pg_restore sha256sum realpath; do command -v "$tool" >/dev/null || fail "Missing tool: $tool"; done
[[ -x $PHPCLI && -f $backend/artisan && -f $backend/.env && -s $backup ]] || fail 'Invalid backend, backup or PHP CLI.'
[[ -f $backup.sha256 ]] || fail 'Missing backup checksum.'
(cd "$(dirname "$backup")" && sha256sum --check "$(basename "$backup").sha256")
pg_restore --list "$backup" >/dev/null

connection=$(
  cd "$backend"
  "$PHPCLI" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo json_encode(config("database.connections.pgsql"), JSON_THROW_ON_ERROR);'
)
host=$(jq -er '.host' <<< "$connection")
port=$(jq -er '.port|tostring' <<< "$connection")
database=$(jq -er '.database' <<< "$connection")
username=$(jq -er '.username' <<< "$connection")
password=$(jq -er '.password // ""' <<< "$connection")
PGPASSWORD=$password pg_restore --host "$host" --port "$port" --username "$username" --dbname "$database" --clean --if-exists --no-owner --no-privileges "$backup"

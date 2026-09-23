#!/usr/bin/env bash
# Run on CoreServer. No application changes without --apply and a DB backup reference.
set -Eeuo pipefail
umask 077

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
check_environment() {
  php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (!$app->environment("production") || config("app.debug")) { fwrite(STDERR, "Expected production with debug disabled\n"); exit(1); }'
}
usage() {
  echo 'Usage: bash deploy.sh vX.Y.Z [--apply --db-backup BACKUP_REFERENCE]'
  echo 'Default: preflight only. Paths: ~/famie, ~/public_html/famie.ka2.org'
}
if [[ ${1:-} == --help ]]; then usage; exit 0; fi
tag=${1:-}
[[ $tag =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { usage; exit 1; }
shift
apply=0
db_backup=''
while (($#)); do
  case $1 in
    --apply) apply=1; shift ;;
    --db-backup) (($# >= 2)) || fail 'Missing backup reference'; db_backup=$2; shift 2 ;;
    *) fail "Unknown argument: $1" ;;
  esac
done
[[ $apply == 0 || -n $db_backup ]] || fail 'Create a DB backup first and pass --db-backup REFERENCE.'
for tool in git php composer rsync curl jq realpath; do
  command -v "$tool" >/dev/null || fail "Missing tool: $tool"
done

repo=$(realpath "$HOME/famie")
docroot=$(realpath "$HOME/public_html/famie.ka2.org")
backup_root="$HOME/famie-release-backups/releases"
[[ $repo == "$HOME/famie" && $docroot == "$HOME/public_html/famie.ka2.org" ]] || fail 'Unexpected or symlinked deployment path.'
[[ -d $repo/.git && -f $repo/backend/.env ]] || fail 'Initial backend setup is required.'
[[ -f $docroot/.htaccess && -f $docroot/index.html ]] || fail 'Initial frontend setup is required.'
[[ -L $docroot/api && $(realpath "$docroot/api") == "$repo/backend/public" ]] || fail 'Unexpected API symlink.'
[[ ! -f $repo/backend/storage/framework/down ]] || fail 'Backend is already in maintenance mode.'
cd "$repo"
[[ -z $(git status --porcelain) ]] || fail 'Repository has uncommitted changes.'

mkdir -p "$backup_root"
lock="$backup_root/.deploy-lock"
mkdir "$lock" 2>/dev/null || fail 'Another deployment is running (or its lock needs investigation).'
started=0
backup=''
finish() {
  status=$?
  trap - EXIT
  if ((status != 0)); then
    echo "Deployment failed (exit $status). Backup: ${backup:-not created}" >&2
    if ((started)); then
      (cd "$repo/backend" && php artisan down --retry=60) || true
      echo 'Backend remains in maintenance mode. Inspect the log and follow the recovery runbook before artisan up.' >&2
    fi
  fi
  rmdir "$lock"
  exit "$status"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

git fetch origin main --tags
commit=$(git rev-parse --verify "refs/tags/$tag^{commit}")
git merge-base --is-ancestor "$commit" origin/main || fail 'Tag is not on origin/main.'
version=$(git show "$commit:frontend/package.json" | jq -er '.version')
[[ $tag == "v$version" ]] || fail 'Tag and frontend package version do not match.'
for asset in index.html .htaccess sw.js manifest.webmanifest; do
  git cat-file -e "$commit:frontend/.output/public/$asset" || fail "Missing tagged asset: $asset"
done
git cat-file -e "$commit:backend/composer.lock"
cd "$repo/backend"
check_environment
echo "Verified tag: $tag ($commit)"
if ((apply == 0)); then
  echo 'Preflight passed. Application and DB were not changed. Review migrations and create a DB backup before --apply.'
  exit 0
fi

previous=$(git -C "$repo" rev-parse HEAD)
release_id="$(date +%Y%m%d-%H%M%S)-$tag-$$"
backup="$backup_root/$release_id"
mkdir "$backup"
exec > >(tee -a "$backup/deploy.log") 2>&1
printf '%s\n' "$previous" > "$backup/previous-commit"
printf '%s\n' "$commit" > "$backup/release-commit"
printf '%s\n' "$db_backup" > "$backup/db-backup-reference"
rsync -a --exclude='/api' "$docroot/" "$backup/frontend/"

# Put the live backend into maintenance before replacing its code or dependencies.
php artisan down --retry=60
started=1
git -C "$repo" checkout --detach "$commit"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan optimize:clear
check_environment
php artisan migrate --force --no-interaction
php artisan db:seed --class=CategorySeeder --force --no-interaction
php artisan optimize
rsync -a --delete --exclude='/.htaccess' --exclude='/api' \
  "$repo/frontend/.output/public/" "$docroot/"
php artisan up

base_url='https://famie.ka2.org'
for path in / /login/; do
  status=$(curl --silent --show-error --max-time 30 -o /dev/null -w '%{http_code}' "$base_url$path")
  [[ $status == 200 ]] || fail "Frontend check failed: $path HTTP $status"
done
status=$(curl --silent --show-error --max-time 30 -o "$backup/api-check.json" -w '%{http_code}' \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{}' "$base_url/api/v1/auth/login")
[[ $status == 422 ]] || fail "API check failed: HTTP $status"
jq -e '.errors.login and .errors.password' "$backup/api-check.json" >/dev/null
started=0
printf 'Released %s (%s)\nBackup and log: %s\n' "$tag" "$commit" "$backup"

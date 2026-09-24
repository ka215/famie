#!/usr/bin/env bash
# Run on CoreServer. No application changes without --apply and a DB backup reference.
set -Eeuo pipefail
umask 077

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
# Run Composer and Artisan with the same explicitly selected PHP CLI.
initialize_cli() {
  PHPCLI=${PHPCLI:-/usr/local/bin/php84cli}
  [[ $PHPCLI == /* && -x $PHPCLI ]] || fail "PHPCLI must be an absolute executable path: $PHPCLI"
  local sapi
  sapi=$("$PHPCLI" -r 'echo PHP_SAPI;') || fail 'PHPCLI must point to PHP CLI, not CGI/FastCGI.'
  [[ $sapi == cli ]] || fail 'PHPCLI must point to PHP CLI, not CGI/FastCGI.'
  # Match the server's composer alias without relying on interactive shell aliases.
  COMPOSER_FILE=${COMPOSER_FILE:-"$HOME/bin/composer.phar"}
  [[ -n $COMPOSER_FILE && -f $COMPOSER_FILE && -r $COMPOSER_FILE ]] ||
    fail 'Set COMPOSER_FILE to a readable Composer PHP script or composer.phar.'
  COMPOSER_FILE=$(realpath "$COMPOSER_FILE")
  "$PHPCLI" -r '$s = file_get_contents($argv[1], false, null, 0, 1024); exit(preg_match("/\A(?:#![^\r\n]*\r?\n)?<\?php\b/", $s) ? 0 : 1);' "$COMPOSER_FILE" ||
    fail 'Composer is a wrapper, not a PHP file. Set COMPOSER_FILE to the actual composer.phar or PHP script.'
  "$PHPCLI" "$COMPOSER_FILE" --version --no-ansi || fail 'Composer could not run with PHPCLI.'
}
resolve_release() {
  local commit=$1 requested_tag=${2:-} version_filter version_json backend_json frontend_json metadata current state tag_commit
  version_filter=$(git show "$commit:scripts/release/version-state.jq") || return 1
  version_json=$(git show "$commit:version.json") || return 1
  backend_json=$(git show "$commit:backend/package.json") || return 1
  frontend_json=$(git show "$commit:frontend/package.json") || return 1
  metadata=$(printf '%s\n' "$version_json" "$backend_json" "$frontend_json" | jq -ser "$version_filter") || return 1
  IFS=$'\t' read -r current version state <<< "$metadata"
  [[ $state == next ]] || fail 'Both package versions must be prepared at next before deployment.'
  tag="v$version"
  [[ -z $requested_tag || $requested_tag == "$tag" ]] || fail "Expected $tag from origin/main; received $requested_tag."
  tag_commit=$(git rev-parse --verify "refs/tags/$tag^{commit}") || return 1
  [[ $tag_commit == "$commit" ]] || fail 'Release tag must point to the exact origin/main commit.'
}
check_environment() {
  "$PHPCLI" "$script_dir/check-deploy-environment.php" "$repo/backend" "$base_url" "$database"
}
select_environment() {
  case $environment in
    production) app_name=famie; domain=famie.ka2.org; database=ka2_famie ;;
    staging) app_name=famie-stg; domain=stg-famie.ka2.org; database=ka2_famiestg ;;
    *) fail 'Environment must be production or staging.' ;;
  esac
  repo="$HOME/$app_name"
  docroot="$HOME/public_html/$domain"
  base_url="https://$domain"
  backup_root="$HOME/$app_name-release-backups/releases"
}
resolve_staging() {
  local ref=$1 branch found=0 version_filter metadata
  commit=$(git rev-parse --verify "$ref^{commit}") || return 1
  if [[ $ref == v* ]]; then
    [[ $commit == $(git rev-parse --verify 'origin/main^{commit}') ]] || fail 'Final staging tag must match origin/main.'
    resolve_release "$commit" "$ref"
    source_ref=origin/main
  else
    [[ $ref == "$commit" ]] || fail 'Staging requires a full commit SHA or release tag.'
    while IFS= read -r branch; do
      [[ $branch == refs/remotes/origin/dev || $branch == refs/remotes/origin/feature/* || $branch == refs/remotes/origin/hotfix/* ]] || continue
      if git merge-base --is-ancestor "$commit" "$branch"; then
        source_ref=$branch; found=1; break
      fi
    done < <(git for-each-ref --format='%(refname)' refs/remotes/origin/dev 'refs/remotes/origin/feature/*' 'refs/remotes/origin/hotfix/*')
    ((found)) || fail 'Staging commit must belong to fetched dev, feature/* or hotfix/*.'
    version_filter=$(git show "$commit:scripts/release/version-state.jq") || return 1
    metadata=$({ git show "$commit:version.json"; git show "$commit:backend/package.json"; git show "$commit:frontend/package.json"; } | jq -ser "$version_filter") || return 1
    version=$(cut -f2 <<< "$metadata")
    tag="candidate-$version-${commit:0:12}"
  fi
}
publish_frontend() {
  rsync -a --chmod=D705,F604 --delete --exclude='/.htaccess' --exclude='/api' --exclude='/.maintenance' \
    "$repo/frontend/.output/public/" "$docroot/"
}
enable_maintenance() {
  : > "$docroot/.maintenance" || return 1
  (cd "$repo/backend" && "$PHPCLI" artisan down --retry=60)
}
disable_maintenance() {
  (cd "$repo/backend" && "$PHPCLI" artisan up) || return 1
  rm -- "$docroot/.maintenance"
}
check_maintenance_setup() {
  local expected actual asset
  expected=$(git show "$commit:frontend/.output/public/.htaccess" | sed -n '/^# BEGIN FAMIE MAINTENANCE$/,/^# END FAMIE MAINTENANCE$/p')
  actual=$(sed -n '/^# BEGIN FAMIE MAINTENANCE$/,/^# END FAMIE MAINTENANCE$/p' "$docroot/.htaccess")
  [[ -n $expected && $actual == "$expected" ]] || fail 'Install the maintenance block before existing rewrite rules; preserve the production IP restrictions. See the runbook.'
  for asset in maintenance.html maintenance.json; do
    git show "$commit:frontend/.output/public/$asset" | cmp - "$docroot/$asset" ||
      fail "Install the reviewed $asset before deployment. See the runbook."
  done
}
check_maintenance_http() {
  local status
  status=$(curl --silent --show-error --max-time 30 -o "$backup/maintenance-check.html" -w '%{http_code}' "$base_url/")
  [[ $status == 503 ]] || fail "Frontend maintenance check failed: HTTP $status"
  grep -F 'ただいまメンテナンス中です' "$backup/maintenance-check.html" >/dev/null || fail 'Maintenance page is missing.'
  status=$(curl --silent --show-error --max-time 30 -o "$backup/maintenance-check.json" -w '%{http_code}' -H 'Accept: application/json' "$base_url/api/v1/status")
  [[ $status == 503 ]] || fail "API maintenance check failed: HTTP $status"
  jq -e '.code == "maintenance"' "$backup/maintenance-check.json" >/dev/null
}
check_public_assets() {
  local asset status
  # Check entry assets too: HTML 200 alone does not catch unreadable JS/CSS.
  local entries
  entries=$(grep -oE '/_nuxt/[^" <>]+\.(js|css)' "$repo/frontend/.output/public/index.html" | sort -u)
  [[ -n $entries ]] || fail 'No entry JS/CSS found in generated index.html.'
  for asset in $entries /sw.js /manifest.webmanifest; do
    status=$(curl --silent --show-error --max-time 30 -o /dev/null -w '%{http_code}' "$base_url$asset")
    [[ $status == 200 ]] || fail "Static asset check failed: $asset HTTP $status"
  done
}
finish() {
  status=$?
  trap - EXIT
  if ((status != 0)); then
    echo "Deployment failed at: $stage (exit $status). Backup: ${backup:-not created}" >&2
    if ((started)); then
      if enable_maintenance; then
        echo 'Frontend and backend maintenance enabled.' >&2
      else
        echo 'WARNING: could not enable maintenance completely. Check .maintenance and storage/framework/down before recovery.' >&2
      fi
      printf 'Recovery: inspect %s/deploy.log, previous-commit and db-backup-reference.\n' "$backup" >&2
      printf 'Use PHPCLI=%q and COMPOSER_FILE=%q for recovery.\n' "$PHPCLI" "$COMPOSER_FILE" >&2
      echo 'Follow docs/operations/runbooks/release-script-notes.md section 4; do not run artisan up before recovery checks.' >&2
    fi
  fi
  rmdir "$lock"
  if [[ ${log_redirected:-0} == 1 ]]; then
    exec 1>&3 2>&4
    tail -n 40 "$backup/deploy.log"
    printf 'Full deployment log: %s/deploy.log\n' "$backup"
  fi
  exit "$status"
}
usage() {
  echo 'Usage: bash deploy.sh [vX.Y.Z] [--env production|staging] [--ref FULL_SHA_OR_TAG] [--apply --db-backup BACKUP_REFERENCE]'
  echo 'Defaults: PHPCLI=/usr/local/bin/php84cli, COMPOSER_FILE=$HOME/bin/composer.phar.'
  echo 'Version is derived from origin/main version.json. An optional tag must match it.'
  echo 'Default: production, preflight only. Staging requires --ref; production rejects --ref.'
  echo 'Install check-deploy-environment.php beside this script.'
}
# Allow the version resolver to be tested against a disposable local Git repository.
if [[ ${BASH_SOURCE[0]} != "$0" ]]; then return 0; fi
deployment_script=$(realpath "${BASH_SOURCE[0]}")
script_dir=$(dirname "$deployment_script")
if [[ ${1:-} == --help ]]; then usage; exit 0; fi
requested_tag=''
if [[ -n ${1:-} && $1 != --* ]]; then
  requested_tag=$1
  [[ $requested_tag =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || { usage; exit 1; }
  shift
fi
apply=0
db_backup=''
environment=production
requested_ref=''
environment_seen=0
while (($#)); do
  case $1 in
    --env)
      (($# >= 2 && environment_seen == 0)) || fail 'Specify --env once with production or staging.'
      environment=$2; environment_seen=1; shift 2 ;;
    --ref)
      (($# >= 2)) && [[ -z $requested_ref ]] || fail 'Specify --ref once with a full SHA or tag.'
      requested_ref=$2; shift 2 ;;
    --apply) apply=1; shift ;;
    --db-backup)
      (($# >= 2)) || fail 'Missing backup reference'
      [[ $2 != --* && $2 =~ [^[:space:]] ]] || fail 'Missing backup reference'
      db_backup=$2; shift 2 ;;
    *) fail "Unknown argument: $1" ;;
  esac
done
select_environment
if [[ $environment == production ]]; then
  [[ -z $requested_ref ]] || fail 'Production does not accept --ref.'
else
  [[ -z $requested_tag ]] || fail 'Use --ref for staging.'
  [[ $requested_ref =~ ^([0-9a-f]{40}|v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*))$ ]] || fail 'Staging requires --ref with a full commit SHA or release tag.'
fi
[[ $apply == 0 || -n $db_backup ]] || fail 'Create a DB backup first and pass --db-backup REFERENCE.'
for tool in git rsync curl jq realpath sed cmp grep cut sort tail; do
  command -v "$tool" >/dev/null || fail "Missing tool: $tool"
done

initialize_cli
[[ -f $script_dir/check-deploy-environment.php ]] || fail 'Missing check-deploy-environment.php beside deployment script.'

[[ $(realpath "$repo") == "$repo" && $(realpath "$docroot") == "$docroot" ]] || fail 'Unexpected or symlinked deployment path.'
[[ -d $repo/.git && -f $repo/backend/.env ]] || fail 'Initial backend setup is required.'
[[ -f $docroot/.htaccess && -f $docroot/index.html ]] || fail 'Initial frontend setup is required.'
[[ -L $docroot/api && $(realpath "$docroot/api") == "$repo/backend/public" ]] || fail 'Unexpected API symlink.'
[[ ! -f $repo/backend/storage/framework/down ]] || fail 'Backend is already in maintenance mode.'
[[ ! -e $docroot/.maintenance ]] || fail 'Frontend is already in maintenance mode.'
cd "$repo"
[[ -z $(git status --porcelain) ]] || fail 'Repository has uncommitted changes.'

mkdir -p "$backup_root"
[[ $(realpath "$backup_root") == "$backup_root" ]] || fail 'Unexpected or symlinked backup path.'
lock="$backup_root/.deploy-lock"
mkdir "$lock" 2>/dev/null || fail 'Another deployment is running (or its lock needs investigation).'
started=0
backup=''
stage='fetch and release validation'
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

if [[ $environment == production ]]; then
  git fetch origin '+refs/heads/main:refs/remotes/origin/main' --tags
  commit=$(git rev-parse --verify 'origin/main^{commit}')
  resolve_release "$commit" "$requested_tag"
  source_ref=origin/main
else
  git fetch --prune origin '+refs/heads/*:refs/remotes/origin/*' --tags
  resolve_staging "$requested_ref"
fi
for asset in index.html .htaccess sw.js manifest.webmanifest maintenance.html maintenance.json; do
  git cat-file -e "$commit:frontend/.output/public/$asset" || fail "Missing tagged asset: $asset"
done
git cat-file -e "$commit:backend/composer.lock"
stage='maintenance setup validation'
check_maintenance_setup
cd "$repo/backend"
stage='deployment environment validation'
check_environment
printf 'Environment: %s\nRepository: %s\nPublic: %s\nURL: %s\nDatabase/schema: %s\nSource: %s\nVerified release: %s (%s)\n' "$environment" "$repo" "$docroot" "$base_url" "$database" "$source_ref" "$tag" "$commit"
if ((apply == 0)); then
  echo 'Preflight passed. Application and DB were not changed. Review migrations and create a DB backup before --apply.'
  exit 0
fi

previous=$(git -C "$repo" rev-parse HEAD)
stage='backup'
release_id="$(date +%Y%m%d-%H%M%S)-$tag-$$"
backup="$backup_root/$release_id"
mkdir "$backup"
printf 'Deploying %s; log: %s/deploy.log\n' "$environment" "$backup"
# Write directly: a closed SSH output pipe must not terminate deployment logging.
exec 3>&1 4>&2
exec >> "$backup/deploy.log" 2>&1
log_redirected=1
printf '%s\n' "$previous" > "$backup/previous-commit"
printf '%s\n' "$commit" > "$backup/release-commit"
printf '%s\n' "$db_backup" > "$backup/db-backup-reference"
printf '%s\n' "$environment" > "$backup/environment"
printf '%s\n' "$source_ref" > "$backup/source-ref"
cp "$script_dir/check-deploy-environment.php" "$backup/"
cp "$deployment_script" "$backup/deploy.sh"
rsync -a --exclude='/api' "$docroot/" "$backup/frontend/"

# Put the live backend into maintenance before replacing its code or dependencies.
stage='enable maintenance'
started=1
enable_maintenance
stage='maintenance HTTP checks'
check_maintenance_http
stage='checkout release'
git -C "$repo" checkout --detach "$commit"
stage='composer install'
"$PHPCLI" "$COMPOSER_FILE" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
stage='clear caches and validate environment'
"$PHPCLI" artisan optimize:clear
check_environment
stage='database migration'
"$PHPCLI" artisan migrate --force --no-interaction
stage='category seed'
"$PHPCLI" artisan db:seed --class=CategorySeeder --force --no-interaction
stage='optimize'
"$PHPCLI" artisan optimize
# Git checkout under umask 077 creates owner-only files. Publish readable assets
# explicitly, while keeping backups private and the server .htaccess untouched.
stage='publish frontend'
publish_frontend
stage='verify deployment before reopening'
cmp "$backup/frontend/.htaccess" "$docroot/.htaccess"
[[ -L $docroot/api && $(realpath "$docroot/api") == "$repo/backend/public" ]] || fail 'API link changed during deployment.'
check_environment
check_maintenance_http
stage='disable maintenance'
disable_maintenance

stage='public HTTP checks'
for path in / /login/; do
  status=$(curl --silent --show-error --max-time 30 -o /dev/null -w '%{http_code}' "$base_url$path")
  [[ $status == 200 ]] || fail "Frontend check failed: $path HTTP $status"
done
status=$(curl --silent --show-error --max-time 30 -o "$backup/api-check.json" -w '%{http_code}' \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{}' "$base_url/api/v1/auth/login")
[[ $status == 422 ]] || fail "API check failed: HTTP $status"
jq -e '.errors.login and .errors.password' "$backup/api-check.json" >/dev/null
status=$(curl --silent --show-error --max-time 30 -o "$backup/status-check.json" -w '%{http_code}' "$base_url/api/v1/status")
[[ $status == 200 ]] || fail "Recovery status check failed: HTTP $status"
jq -e '.status == "ok"' "$backup/status-check.json" >/dev/null
check_public_assets
started=0
printf 'Released %s (%s)\nBackup and log: %s\n' "$tag" "$commit" "$backup"

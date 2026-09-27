#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
usage() { echo 'Usage: deploy-artifact.sh --env staging|production --bundle FILE --checksum FILE [--apply] [--rollback] [--restore-db FILE]'; }

environment= bundle= checksum= restore_db= apply=0 rollback=0
while (($#)); do
  case $1 in
    --env) environment=${2:-}; shift 2 ;;
    --bundle) bundle=${2:-}; shift 2 ;;
    --checksum) checksum=${2:-}; shift 2 ;;
    --restore-db) restore_db=${2:-}; shift 2 ;;
    --apply) apply=1; shift ;;
    --rollback) rollback=1; shift ;;
    *) usage; fail "Unknown argument: $1" ;;
  esac
done
[[ $environment == staging || $environment == production ]] || fail 'Environment must be staging or production.'
[[ -f $bundle && -f $checksum ]] || fail 'Bundle and checksum files are required.'
[[ -z $restore_db || -f $restore_db ]] || fail 'Requested DB backup does not exist.'
for tool in jq tar sha256sum rsync curl realpath grep sort; do command -v "$tool" >/dev/null || fail "Missing tool: $tool"; done
PHPCLI=${PHPCLI:-/usr/local/bin/php84cli}
COMPOSER_FILE=${COMPOSER_FILE:-"$HOME/bin/composer.phar"}
[[ -x $PHPCLI && -r $COMPOSER_FILE ]] || fail 'PHPCLI and COMPOSER_FILE must be usable.'

case $environment in
  production) app_name=famie; domain=famie.ka2.org; database=ka2_famie ;;
  staging) app_name=famie-stg; domain=stg-famie.ka2.org; database=ka2_famiestg ;;
esac
repo="$HOME/$app_name"
docroot="$HOME/public_html/$domain"
base_url="https://$domain"
root="$HOME/$app_name-release-backups"
releases="$root/releases"
incoming="$root/incoming"
mkdir -p "$releases" "$incoming"
repo=$(realpath "$repo")
docroot=$(realpath "$docroot")
[[ -f $repo/backend/.env && -f $docroot/.htaccess && -L $docroot/api ]] || fail 'Environment is not initialized.'
[[ $(realpath "$docroot/api") == "$repo/backend/public" ]] || fail 'Unexpected API symlink.'
guard="$(dirname "$0")/check-deploy-environment.php"
[[ -f $guard ]] || fail 'Missing environment guard.'
"$PHPCLI" "$guard" "$repo/backend" "$base_url" "$database"

expected=$(cut -d' ' -f1 "$checksum")
actual=$(sha256sum "$bundle" | cut -d' ' -f1)
[[ $expected == "$actual" ]] || fail 'Release bundle checksum mismatch.'
work=$(mktemp -d "$incoming/deploy.XXXXXX")
trap 'rm -rf -- "$work"' EXIT
tar -xzf "$bundle" -C "$work"
[[ -f $work/manifest.json && -f $work/manifest.sha256 && -f $work/payload.tar.gz ]] || fail 'Invalid release bundle.'
(cd "$work" && sha256sum --check manifest.sha256)
payload_sha=$(jq -er '.payload_sha256' "$work/manifest.json")
[[ $payload_sha == $(sha256sum "$work/payload.tar.gz" | cut -d' ' -f1) ]] || fail 'Payload checksum mismatch.'
version=$(jq -er '.release_version' "$work/manifest.json")
tree=$(jq -er '.source_tree' "$work/manifest.json")
if ((rollback)) && [[ -f $root/current-manifest.json ]] && [[ -z $restore_db ]]; then
  jq -e '.database_rollback_compatible == true' "$root/current-manifest.json" >/dev/null ||
    fail 'Current release changed migrations; specify --restore-db after compatibility review.'
fi
mkdir "$work/payload"
tar -xzf "$work/payload.tar.gz" -C "$work/payload"
(cd "$work/payload" && sha256sum --check checksums.sha256)
node_required="$work/payload/frontend/public/index.html"
[[ -f $node_required && -f $work/payload/backend/composer.lock ]] || fail 'Payload is incomplete.'

printf 'Environment: %s\nVersion: v%s\nTree: %s\nBundle SHA256: %s\n' "$environment" "$version" "$tree" "$actual"
if ((apply == 0)); then echo 'Preflight passed; no changes applied.'; exit 0; fi
[[ ! -e $releases/.deploy-lock ]] || fail 'Another deployment is active.'
mkdir "$releases/.deploy-lock"
started=0
release_dir="$releases/$(date +%Y%m%d-%H%M%S)-v$version-${tree:0:12}"
mkdir "$release_dir"
finish() {
  status=$?
  trap - EXIT
  if ((status != 0 && started)); then
    : > "$docroot/.maintenance" || true
    (cd "$repo/backend" && "$PHPCLI" artisan down --retry=60) || true
    printf 'Deployment failed. Maintenance remains enabled. Evidence: %s\n' "$release_dir" >&2
  fi
  rmdir "$releases/.deploy-lock" 2>/dev/null || true
  rm -rf -- "$work"
  exit "$status"
}
trap finish EXIT
trap 'exit 130' INT TERM HUP
cp "$work/manifest.json" "$release_dir/manifest.json"
printf '%s  %s\n' "$actual" "$(basename "$bundle")" > "$release_dir/bundle.sha256"
rsync -a --exclude='/api' "$docroot/" "$release_dir/frontend/"
rsync -a --exclude='/.env' --exclude='/storage' --exclude='/vendor' "$repo/backend/" "$release_dir/backend/"
db_backup=$("$(dirname "$0")/backup-database.sh" "$repo/backend" "$release_dir")
printf '%s\n' "$db_backup" > "$release_dir/db-backup-reference"

started=1
: > "$docroot/.maintenance"
(cd "$repo/backend" && "$PHPCLI" artisan down --retry=60)
rsync -a --chmod=D705,F604 --delete --exclude='/.env' --exclude='/storage' --exclude='/vendor' "$work/payload/backend/" "$repo/backend/"
(cd "$repo/backend" && "$PHPCLI" "$COMPOSER_FILE" install --no-dev --prefer-dist --optimize-autoloader --no-interaction)
(cd "$repo/backend" && "$PHPCLI" artisan optimize:clear)
(cd "$repo/backend" && "$PHPCLI" artisan migrate:status --no-interaction) > "$release_dir/migration-status-before.txt"
(cd "$repo/backend" && "$PHPCLI" artisan migrate --force --no-interaction)
(cd "$repo/backend" && "$PHPCLI" artisan db:seed --class=CategorySeeder --force --no-interaction)
(cd "$repo/backend" && "$PHPCLI" artisan optimize)
"$PHPCLI" "$guard" "$repo/backend" "$base_url" "$database"
rsync -a --omit-dir-times --delete --chmod=D705,F604 --exclude='/.htaccess' --exclude='/api' --exclude='/.maintenance' "$work/payload/frontend/public/" "$docroot/"
[[ -L $docroot/api && $(realpath "$docroot/api") == "$repo/backend/public" ]] || fail 'API link changed.'
if [[ -n $restore_db ]]; then
  "$(dirname "$0")/restore-database.sh" "$repo/backend" "$restore_db"
  (cd "$repo/backend" && "$PHPCLI" artisan optimize:clear && "$PHPCLI" artisan optimize)
fi
(cd "$repo/backend" && "$PHPCLI" artisan up)
rm -- "$docroot/.maintenance"

for path in / /login/ /settings/ /sw.js /manifest.webmanifest; do
  status=$(curl -sS --max-time 30 -o /dev/null -w '%{http_code}' "$base_url$path")
  [[ $status == 200 ]] || fail "$path returned HTTP $status"
done
entries=$(grep -hoE '/_nuxt/[^" <>]+\.(js|css)' "$docroot"/*.html "$docroot"/*/index.html 2>/dev/null | sort -u)
[[ -n $entries ]] || fail 'No entry assets found after deployment.'
for asset in $entries; do
  headers="$work/headers"
  body="$work/body"
  status=$(curl -sS --max-time 30 -D "$headers" -o "$body" -w '%{http_code}' "$base_url$asset")
  [[ $status == 200 ]] || fail "$asset returned HTTP $status"
  content_type=$(grep -i '^content-type:' "$headers" | tail -1 | tr -d '\r' | cut -d: -f2-)
  [[ $asset == *.js && $content_type == *javascript* || $asset == *.css && $content_type == *text/css* ]] || fail "$asset has invalid Content-Type:$content_type"
  ! grep -qi '<!doctype html' "$body" || fail "$asset returned HTML fallback."
done
frontend_drift=$(rsync -ani --omit-dir-times --checksum --delete --chmod=D705,F604 --exclude='/.htaccess' --exclude='/api' --exclude='/.maintenance' "$work/payload/frontend/public/" "$docroot/")
[[ -z $frontend_drift ]] || fail 'Deployed frontend differs from the verified payload.'
cp "$work/manifest.json" "$release_dir/deployed-manifest.json"
cp "$work/manifest.json" "$root/current-manifest.json"
printf '%s\n' "$actual" > "$release_dir/deployed-bundle-sha256"
started=0
printf 'Released v%s to %s. Evidence: %s\n' "$version" "$environment" "$release_dir"

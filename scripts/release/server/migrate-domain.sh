#!/usr/bin/env bash
# One-time domain migration of the CURRENT production application; no code/DB release.
set -Eeuo pipefail
umask 077
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
[[ $# == 2 && ( $1 == --prepare || $1 == --switch || $1 == --revert ) ]] ||
  fail 'Usage: migrate-domain.sh --prepare|--switch|--revert PRIVATE_STATE_DIRECTORY'
action=$1
state=$2
export PATH="$HOME/bin:$PATH"
PHPCLI=${PHPCLI:-/usr/local/bin/php84cli}
tools=$(cd "$(dirname "$0")" && pwd)
backend="$HOME/famie/backend"
old="$HOME/public_html/famie.ka2.org"
new="$HOME/public_html/famie.co"
root="$HOME/famie-release-backups"
for tool in rsync curl jq realpath sha256sum find install cmp; do command -v "$tool" >/dev/null || fail "Missing tool: $tool"; done
[[ -x $PHPCLI ]] || fail 'Missing PHP CLI.'
for directory in "$old" "$new" "$backend" "$root"; do
  [[ -d $directory && ! -L $directory && $(realpath "$directory") == "$directory" ]] || fail 'Unexpected directory mapping.'
done
[[ $state == "$root"/domain-migration-* && $state != *..* && $state != *$'\n'* && $state != *'/domain-migration-'*/* ]] || fail 'Invalid private state path.'
[[ -L $old/api && $(realpath "$old/api") == "$backend/public" ]] || fail 'Unexpected old API link.'
[[ ! -e $old/.maintenance && ! -e $new/.maintenance && ! -e $backend/storage/framework/down ]] || fail 'Application is in maintenance.'
for file in check-deploy-environment.php backup-database.sh set-domain-url.php legacy-domain.htaccess domain-moved.json production-https.htaccess; do
  [[ -f $tools/$file ]] || fail "Missing tool file: $file"
done
mkdir "$root/releases/.deploy-lock" || fail 'Another deployment is active.'
switch_started=0
restore_old() {
  # Block the new API before returning the shared application to its old origin.
  install -m 604 "$state/prepared.htaccess" "$new/.htaccess.restore" || return 1
  mv "$new/.htaccess.restore" "$new/.htaccess" || return 1
  cp -p "$state/backend.env" "$backend/.env.domain-restore" || return 1
  mv "$backend/.env.domain-restore" "$backend/.env" || return 1
  (cd "$backend" && "$PHPCLI" artisan config:cache --no-interaction) || return 1
  install -m 604 "$state/old.htaccess" "$old/.htaccess.restore" || return 1
  mv "$old/.htaccess.restore" "$old/.htaccess" || return 1
  "$PHPCLI" "$tools/check-deploy-environment.php" "$backend" https://famie.ka2.org ka2_famie || return 1
}
finish() {
  status=$?
  trap - EXIT
  if (( status != 0 && switch_started )); then
    if restore_old; then echo 'Switch failed; old domain configuration restored.' >&2
    else echo "Automatic restore failed. Inspect private state: $state" >&2; fi
  fi
  rmdir "$root/releases/.deploy-lock" || true
  exit "$status"
}
trap finish EXIT
trap 'exit 130' INT TERM HUP
status_ok() {
  local origin=$1
  curl -fsS --max-time 30 "$origin/api/v1/status" | jq -e '.status == "ok"' >/dev/null
}
if [[ $action == --prepare ]]; then
  "$PHPCLI" "$tools/check-deploy-environment.php" "$backend" https://famie.ka2.org ka2_famie
  [[ $(jq -er '.release_version' "$root/current-manifest.json") == 0.15.2 ]] || fail 'Current release is no longer 0.15.2; review migration.'
  if [[ -e $state ]]; then
    [[ -d $state && ! -L $state && $(realpath "$state") == "$state" && ! -e $state/prepared && -s $state/db-backup-reference ]] || fail 'Cannot resume this state.'
    cmp "$backend/.env" "$state/backend.env" || fail 'Environment changed during preparation.'
    cmp "$old/.htaccess" "$state/old.htaccess" || fail 'Old rules changed during preparation.'
    cmp "$root/current-manifest.json" "$state/original-manifest.json" || fail 'Application changed during preparation.'
    [[ ! -e $new/api || ( -L $new/api && $(realpath "$new/api") == "$backend/public" ) ]] || fail 'Unexpected existing API.'
  else
  [[ ! -e $new/api && ! -L $new/api ]] || fail 'New API already exists.'
  # The new site must still contain only the two disposable placeholder files.
  [[ -z $(find "$new" -mindepth 1 -maxdepth 1 ! -name index.html ! -name favicon.ico -print -quit) ]] || fail 'New site contains unexpected files.'
  mkdir -m 700 "$state"
  cp -p "$backend/.env" "$state/backend.env"
  cp -p "$old/.htaccess" "$state/old.htaccess"
  cp "$root/current-manifest.json" "$state/original-manifest.json"
  rsync -a "$new/" "$state/new-placeholder/"
  rsync -a --exclude='/api' "$old/" "$state/old-frontend/"
  "$tools/backup-database.sh" "$backend" "$state" > "$state/db-backup-reference"
  fi
  # Block non-status API access until the switch; the live old domain is unaffected.
  {
    printf '%s\n' '<If "%{REQUEST_URI} =~ m#^/api(?:/|$)#i && %{THE_REQUEST} !~ m#^GET /api/v1/status(?:\?[^ ]*)? HTTP/#">' 'Redirect 410 /' '</If>'
    printf '%s\n' 'RewriteEngine On' 'RewriteRule ^api/v1/status$ - [L]' 'RewriteRule ^api(?:/|$) - [G,END,NC]'
    cat "$state/old.htaccess"
  } > "$state/prepared.htaccess"
  cat "$tools/production-https.htaccess" "$state/old.htaccess" > "$state/production.htaccess"
  install -m 604 "$state/prepared.htaccess" "$new/.htaccess"
  rsync -a --chmod=D705,F604 --exclude='/api' --exclude='/.htaccess' "$old/" "$new/"
  [[ -L $new/api ]] || ln -s "$backend/public" "$new/api"
  [[ -z $(rsync -anci --omit-dir-times --delete --exclude='/api' --exclude='/.htaccess' "$old/" "$new/") ]] || fail 'Frontend copy differs.'
  status_ok https://famie.co
  [[ $(curl -sS --max-time 30 -o /dev/null -w '%{http_code}' https://famie.co/api/v1/auth/me) == 410 ]] || fail 'Prepared API is not blocked.'
  touch "$state/prepared"
  printf 'Prepared current v0.15.2 at new document root. State: %s\n' "$state"
  exit 0
fi
[[ -d $state && ! -L $state && -f $state/prepared && $(realpath "$state") == "$state" ]] || fail 'Missing prepared state.'
[[ -L $new/api && $(realpath "$new/api") == "$backend/public" ]] || fail 'Unexpected new API link.'
if [[ $action == --revert ]]; then
  [[ -f $state/switched ]] || fail 'No successful switch to revert.'
  cmp "$root/current-manifest.json" "$state/original-manifest.json" || fail 'Another application release occurred; review rollback first.'
  cmp "$backend/.env" "$state/switched.env" || fail 'Environment changed after switching; review rollback first.'
  cmp "$new/.htaccess" "$state/production.htaccess" || fail 'New site rules changed after switching; review rollback first.'
  restore_old
  status_ok https://famie.ka2.org
  {
    printf '%s\n' 'Header always set Cache-Control "no-store, max-age=0"' \
      '<If "%{REQUEST_URI} =~ m#^/api(?:/|$)#i">' 'Redirect 410 /' '</If>' \
      'RewriteEngine On' 'RewriteRule ^api(?:/|$) - [G,END,NC]' \
      'RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$' \
      'RewriteRule ^ https://famie.ka2.org%{REQUEST_URI} [R=302,END,NE]' \
      'RewriteRule ^ - [G,END]'
  } > "$state/reverted.htaccess"
  install -m 604 "$state/reverted.htaccess" "$new/.htaccess.revert"
  mv "$new/.htaccess.revert" "$new/.htaccess"
  mv "$state/switched" "$state/reverted"
  echo 'Old domain restored; DB and storage retained.'
  exit 0
fi
[[ ! -e $state/switched && ! -e $state/reverted ]] || fail 'State was already used for switching.'
"$PHPCLI" "$tools/check-deploy-environment.php" "$backend" https://famie.ka2.org ka2_famie
cmp "$backend/.env" "$state/backend.env" || fail 'Environment changed since preparation.'
cmp "$old/.htaccess" "$state/old.htaccess" || fail 'Old rules changed since preparation.'
cmp "$root/current-manifest.json" "$state/original-manifest.json" || fail 'Application changed since preparation.'
[[ -z $(rsync -anci --omit-dir-times --delete --exclude='/api' --exclude='/.htaccess' "$old/" "$new/") ]] || fail 'Frontend changed since preparation.'
status_ok https://famie.co
switch_started=1
install -m 604 "$tools/domain-moved.json" "$old/domain-moved.json"
install -m 604 "$tools/legacy-domain.htaccess" "$old/.htaccess.migration"
mv "$old/.htaccess.migration" "$old/.htaccess"
"$PHPCLI" "$tools/set-domain-url.php" "$backend" https://famie.ka2.org https://famie.co
(cd "$backend" && "$PHPCLI" artisan config:cache --no-interaction)
"$PHPCLI" "$tools/check-deploy-environment.php" "$backend" https://famie.co ka2_famie
install -m 604 "$state/production.htaccess" "$new/.htaccess.migration"
mv "$new/.htaccess.migration" "$new/.htaccess"
status_ok https://famie.co
[[ $(curl -sS --max-time 30 -o /dev/null -w '%{http_code}' https://famie.ka2.org/api/v1/status) == 410 ]] || fail 'Old API is still active.'
[[ $(curl -sS --max-time 30 -o /dev/null -w '%{http_code}' https://famie.ka2.org/) == 302 ]] || fail 'Old page redirect failed.'
cp -p "$backend/.env" "$state/switched.env"
touch "$state/switched"
switch_started=0
echo 'Domain switched to famie.co; application version, DB and storage retained.'

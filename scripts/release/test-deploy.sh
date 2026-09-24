#!/usr/bin/env bash
# Isolated checks only: no network, live checkout, database or deployment.
set -Eeuo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/deploy.sh"
fixture=$(mktemp -d "${TMPDIR:-/tmp}/famie-deploy-test.XXXXXX")
echo "Test fixture (retained for inspection): $fixture"
repo="$fixture/repo"
docroot="$fixture/public"
backup="$fixture/backup"
lock="$fixture/lock"
mkdir -p "$repo/backend/public" "$docroot" "$backup" "$lock"
PHPCLI="$fixture/selected-php"
COMPOSER_FILE="$fixture/composer.phar"
export FAMIE_TEST_CALLS="$fixture/php-calls"
cat > "$PHPCLI" <<'PHP'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAMIE_TEST_CALLS"
exit "${FAMIE_TEST_PHP_STATUS:-0}"
PHP
chmod +x "$PHPCLI"
stage='composer install'
started=0
if (trap finish EXIT; exit 7) > "$fixture/preflight.log" 2>&1; then fail 'Failure swallowed'; else result=$?; fi
[[ $result == 7 && ! -e $FAMIE_TEST_CALLS && ! -d $lock && ! -e $docroot/.maintenance ]] || fail 'Preflight failure affected maintenance or lost exit status'
mkdir "$lock"
started=1
if (trap finish EXIT; exit 9) > "$fixture/failure.log" 2>&1; then fail 'Failure swallowed'; else result=$?; fi
[[ $result == 9 && ! -d $lock && -f $docroot/.maintenance ]] || fail 'Failure lost exit status, lock or maintenance marker'
[[ $(cat "$FAMIE_TEST_CALLS") == 'artisan down --retry=60' ]] || fail 'Recovery did not use the selected CLI'
grep -F 'composer install' "$fixture/failure.log" >/dev/null
grep -F "$backup/deploy.log" "$fixture/failure.log" >/dev/null
grep -F 'db-backup-reference' "$fixture/failure.log" >/dev/null
mkdir "$lock"
if (export FAMIE_TEST_PHP_STATUS=1; trap finish EXIT; exit 8) > "$fixture/maintenance-failure.log" 2>&1; then fail 'Failure swallowed'; else result=$?; fi
[[ $result == 8 && ! -d $lock ]] || fail 'Maintenance failure lost original exit status or lock'
grep -F 'WARNING: could not enable maintenance completely' "$fixture/maintenance-failure.log" >/dev/null
[[ -f $docroot/.maintenance ]] || fail 'PHP failure cleared frontend maintenance'
disable_maintenance
[[ ! -e $docroot/.maintenance ]] || fail 'Recovery did not remove maintenance marker'
mkdir "$lock"
stage='public HTTP checks'
if (trap finish EXIT; exit 6) > "$fixture/reopen-failure.log" 2>&1; then fail 'Failure swallowed'; else result=$?; fi
[[ $result == 6 && -f $docroot/.maintenance && ! -d $lock ]] || fail 'HTTP failure after reopening did not restore maintenance'
if (export FAMIE_TEST_PHP_STATUS=1; disable_maintenance); then fail 'Failed artisan up was accepted'; fi
[[ -f $docroot/.maintenance ]] || fail 'Failed artisan up removed frontend maintenance'
enable_maintenance
echo '5 failure/recovery scenarios passed (stub PHP only).'

mkdir -p "$repo/frontend/.output/public"
printf '<script src="/_nuxt/app.js"></script><link href="/_nuxt/app.css">' > "$repo/frontend/.output/public/index.html"
base_url=https://staging.invalid
curl() { printf '%s' "${FAMIE_TEST_HTTP_STATUS:-200}"; }
check_public_assets
if (export FAMIE_TEST_HTTP_STATUS=403; check_public_assets) > "$fixture/asset-failure.log" 2>&1; then fail 'Unreadable entry asset accepted'; fi
grep -F 'Static asset check failed' "$fixture/asset-failure.log" >/dev/null
unset -f curl
mkdir "$lock"
if (
  exec 3>&1 4>&2
  exec >> "$backup/deploy.log" 2>&1
  log_redirected=1
  trap finish EXIT
  exit 5
) > "$fixture/direct-log-failure.log" 2>&1; then fail 'Logged failure swallowed'; else result=$?; fi
[[ $result == 5 && -f $docroot/.maintenance && ! -d $lock ]] || fail 'Direct log recovery failed'
grep -F 'Full deployment log:' "$fixture/direct-log-failure.log" >/dev/null
echo 'Static asset rejection and direct-file failure logging passed.'

case $(uname -s) in
  MINGW*|MSYS*|CYGWIN*) echo 'SKIP: POSIX permission checks require Linux/CoreServer and real rsync.'; exit 2 ;;
esac
command -v rsync >/dev/null || fail 'POSIX checks require rsync'
mode() { stat -c '%a' "$1"; }
# Verify the filesystem actually supports owner-only permissions first.
[[ $(mode "$backup") == 700 ]] || fail 'Fixture filesystem does not honor umask 077'
source_dir="$repo/frontend/.output/public"
mkdir -p "$source_dir/login" "$source_dir/_nuxt"
printf 'new index\n' > "$source_dir/index.html"
printf 'login\n' > "$source_dir/login/index.html"
printf 'asset\n' > "$source_dir/_nuxt/app.js"
printf 'source htaccess must not replace IP restrictions\n' > "$source_dir/.htaccess"
printf 'source api must not replace link\n' > "$source_dir/api"
printf 'Require ip 192.0.2.1\n' > "$docroot/.htaccess"
chmod 604 "$docroot/.htaccess"
cp -p "$docroot/.htaccess" "$fixture/expected-htaccess"
ln -s "$repo/backend/public" "$docroot/api"
printf 'private\n' > "$repo/backend/.env"
printf 'old\n' > "$docroot/index.html"
printf 'stale\n' > "$docroot/stale.js"
rsync -a --exclude='/api' "$docroot/" "$backup/frontend/"
printf 'backup reference\n' > "$backup/db-backup-reference"
publish_frontend
cmp "$source_dir/index.html" "$docroot/index.html"
[[ ! -e $docroot/stale.js ]] || fail 'Stale asset was not removed'
[[ -f $docroot/.maintenance ]] || fail 'Publishing cleared maintenance marker'
for directory in "$docroot" "$docroot/login" "$docroot/_nuxt"; do
  [[ $(mode "$directory") == 705 ]] || fail "Wrong public directory mode: $directory"
done
for file in "$docroot/index.html" "$docroot/login/index.html" "$docroot/_nuxt/app.js"; do
  [[ $(mode "$file") == 604 ]] || fail "Wrong public file mode: $file"
done
cmp "$fixture/expected-htaccess" "$docroot/.htaccess"
[[ $(mode "$docroot/.htaccess") == 604 ]] || fail 'Changed htaccess permissions'
[[ -L $docroot/api && $(readlink "$docroot/api") == "$repo/backend/public" ]] || fail 'Changed API link'
[[ $(mode "$repo/backend") == 700 && $(mode "$repo/backend/.env") == 600 ]] || fail 'Changed backend permissions'
[[ $(mode "$backup") == 700 && $(mode "$backup/db-backup-reference") == 600 ]] || fail 'Backup is not private'
[[ $(cat "$backup/frontend/index.html") == old && ! -e $backup/frontend/api ]] || fail 'Backup contents changed'
echo 'PASS: POSIX public modes 705/604, private backup 700/600, backend, IP restrictions and API link preserved.'

#!/usr/bin/env bash
# Synthetic fixtures only, on a disposable GitHub-hosted Linux runner.
set -Eeuo pipefail
umask 077
[[ ${GITHUB_ACTIONS:-} == true && ${RUNNER_ENVIRONMENT:-} == github-hosted && ${RUNNER_OS:-} == Linux ]] || exit 1
[[ $# == 2 && $2 == 61ed81421c3645fa58b339bc05500fdd117bc2fa ]] || exit 1
candidate=$(realpath "$1")
[[ $candidate == "$(realpath "$GITHUB_WORKSPACE")/candidate" && ! -L $1 ]] || exit 1
[[ $(git -C "$candidate" rev-parse HEAD) == "$2" ]] || exit 1
git -C "$candidate" diff --exit-code HEAD -- .
for tool in docker jq psql pg_dump pg_restore timeout openssl php; do command -v "$tool" >/dev/null; done
private=$(mktemp -d "$RUNNER_TEMP/famie-linux-drill.XXXXXXXX")
chmod 700 "$private"
container_id=
label="$GITHUB_RUN_ID.$GITHUB_RUN_ATTEMPT"
log="$private/events.log"
mkdir "$private/report"
printf 'candidate=%s\nharness=%s\n' "$2" "$(git -C "$(dirname "$0")" rev-parse HEAD)" > "$private/report/source.log"
echo "drill_report=$private/report" >> "$GITHUB_ENV"
finish() {
  local result=$?
  trap - EXIT
  set +e
  printf 'event=finished exit=%s\n' "$result" >> "$log"
  if [[ $container_id =~ ^[a-f0-9]{64}$ ]]; then
    if [[ $(docker inspect --format '{{index .Config.Labels "famie.f03.drill"}}' "$container_id") == "$label" ]]; then
      local cleanup_result=0
      docker rm -f "$container_id" >/dev/null || cleanup_result=$?
      printf 'event=container_cleanup exit=%s\n' "$cleanup_result" >> "$log"
      if ((result == 0 && cleanup_result != 0)); then result=$cleanup_result; fi
    else
      printf 'event=container_cleanup_refused\n' >> "$log"
      if ((result == 0)); then result=1; fi
    fi
  fi
  cp "$log" "$private/report/events.log"
  # Passwords are synthetic but still excluded from retained traces.
  for file in "$private"/test-*.log "$private/migration.log"; do
    [[ -f $file ]] || continue
    sed -e "s/${admin_password:-UNSET_ADMIN}/[redacted]/g" -e "s/${runner_password:-UNSET_RUNNER}/[redacted]/g" "$file" > "$private/report/$(basename "$file")"
  done
  exit "$result"
}
trap finish EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
printf 'event=starting\n' >> "$log"
admin_password=$(openssl rand -hex 24)
runner_password=$(openssl rand -hex 24)
printf '::add-mask::%s\n::add-mask::%s\n' "$admin_password" "$runner_password"
printf 'POSTGRES_PASSWORD=%s\nPOSTGRES_DB=famie_test\n' "$admin_password" > "$private/postgres.env"
container_id=$(docker run --detach --rm --label "famie.f03.drill=$label" --publish 127.0.0.1:55432:55432 --env-file "$private/postgres.env" postgres:14 postgres -p 55432)
[[ $container_id =~ ^[a-f0-9]{64}$ ]]
docker image inspect --format '{{.Id}}' postgres:14 > "$private/report/image.log"
ready=0
for attempt in {1..30}; do
  if docker exec "$container_id" pg_isready -h 127.0.0.1 -p 55432 -U postgres -d famie_test >/dev/null 2>&1; then ready=1; break; fi
  sleep 1
done
[[ $ready == 1 ]]
printf 'event=database_ready\n' >> "$log"
export PGHOST=127.0.0.1 PGPORT=55432 PGDATABASE=famie_test PGUSER=postgres PGPASSWORD="$admin_password"
psql -X -v ON_ERROR_STOP=1 -q <<SQL
CREATE ROLE famie_test_runner LOGIN PASSWORD '$runner_password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER DATABASE famie_test OWNER TO famie_test_runner;
REVOKE CONNECT ON DATABASE postgres, template1 FROM PUBLIC;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT ALL ON SCHEMA public TO famie_test_runner;
SQL
export PGUSER=famie_test_runner PGPASSWORD="$runner_password"
identity=$(psql -XAt -v ON_ERROR_STOP=1 -c "select current_database()='famie_test' and current_user='famie_test_runner' and inet_server_port()=55432 and not rolsuper and not rolcreatedb and not rolcreaterole and not rolreplication and not rolbypassrls and not has_database_privilege(current_user,'postgres','CONNECT') and not has_database_privilege(current_user,'template1','CONNECT') from pg_roles where rolname=current_user")
[[ $identity == t ]]
printf 'event=identity_verified server_port=55432 privileged=false\n' >> "$log"
export APP_ENV=testing APP_DEBUG=false APP_URL=http://127.0.0.1
export APP_KEY="base64:$(openssl rand -base64 32)"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=famie_test DB_USERNAME=famie_test_runner DB_PASSWORD="$runner_password" DB_SCHEMA=public DB_URL=
export FAMIE_DB_TEST_RUN=1 FAMIE_TEST_PROFILE=postgres-local FAMIE_LIFECYCLE_PG_TEST=1 FAMIE_LIFECYCLE_PROCESS_TEST=false FAMIE_OPERATIONS_ENABLED=false
export MAIL_MAILER=array MAIL_URL= MAIL_HOST=127.0.0.1 MAIL_PORT=1 MAIL_USERNAME= MAIL_PASSWORD= FAMIE_OPERATIONS_MAILER=array
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync LOG_CHANNEL=stderr
export APP_CONFIG_CACHE="$private/unused-config.php" APP_ROUTES_CACHE="$private/unused-routes.php"
cd "$candidate/backend"
[[ ! -e .env ]]
: > .env
printf 'event=migration_start\n' >> "$log"
php artisan migrate --force --no-interaction > "$private/migration.log" 2>&1
baseline() {
  psql -XAt -v ON_ERROR_STOP=1 -c "select (select count(*) from public.migrations),(select count(*) from public.users),(select count(*) from public.groups),(select count(*) from public.account_lifecycle_operations),(select count(*) from information_schema.schemata where schema_name like 'famie_lifecycle_%_test')"
}
[[ $(baseline) == '19|0|0|0|0' ]]
printf 'event=baseline_verified migrations=19 users=0 groups=0 operations=0 schemas=0\n' >> "$log"
filters=('test_real_cleanup_children_share_only_the_explicit_schema_and_private_fixture$' 'test_real_cleanup_child_rejects|test_parent_refuses|test_synthetic_backup_restore')
expected=(1 13)
for index in 0 1; do
  printf 'event=test_start batch=%s\n' "$index" >> "$log"
  result=0
  timeout --kill-after=5 45 php vendor/bin/phpunit --configuration=phpunit.xml --do-not-cache-result --debug --log-junit "$private/junit-$index.xml" --filter "${filters[$index]}" tests/Feature/AccountLifecycleCleanupPostgresTest.php > "$private/test-$index.log" 2>&1 || result=$?
  printf 'event=test_finished batch=%s exit=%s\n' "$index" "$result" >> "$log"
  [[ $result == 0 ]]
  php -r '$xml=simplexml_load_file($argv[1]); if($xml===false){exit(1);} $cases=$xml->xpath("//testcase"); $failed=count($xml->xpath("//failure|//error|//skipped")); $assertions=0; $duration=0; foreach($cases as $case){$assertions+=(int)$case["assertions"]; $duration+=(float)$case["time"];} $valid=count($cases)===(int)$argv[2] && $failed===0; echo json_encode(["result"=>$valid?"passed":"failed","tests"=>count($cases),"passed"=>count($cases)-$failed,"assertions"=>$assertions,"duration_ms"=>(int)round($duration*1000)],JSON_THROW_ON_ERROR); exit($valid?0:1);' "$private/junit-$index.xml" "${expected[$index]}" > "$private/report/summary-$index.json"
  jq -e --argjson count "${expected[$index]}" '.result=="passed" and .tests==$count and .passed==$count' "$private/report/summary-$index.json" >/dev/null
  [[ $(baseline) == '19|0|0|0|0' ]]
done
printf 'event=postcheck_verified migrations=19 users=0 groups=0 operations=0 schemas=0\n' >> "$log"
git -C "$candidate" diff --exit-code HEAD -- .

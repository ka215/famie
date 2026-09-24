#!/usr/bin/env bash
# Disposable local Git repositories; never contact a deployment environment.
set -Eeuo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/deploy.sh"
original_home=$HOME
fixture=$(mktemp -d "${TMPDIR:-/tmp}/famie-target-test.XXXXXX")
reject() {
  local expected=$1; shift
  local output
  if output=$("$@" 2>&1); then fail "Accepted invalid input: $*"; fi
  [[ $output == *"$expected"* ]] || fail "Unexpected failure: $output"
}
environment=production; select_environment
[[ $repo == "$original_home/famie" && $database == ka2_famie && $base_url == https://famie.ka2.org ]] || fail 'Production mapping'
production_backups=$backup_root
environment=staging; select_environment
[[ $repo == "$original_home/famie-stg" && $database == ka2_famiestg && $base_url == https://stg-famie.ka2.org && $backup_root != "$production_backups" ]] || fail 'Staging mapping'
reject 'Environment must' bash "$script_dir/deploy.sh" --env staging-typo
reject 'Specify --env once' bash "$script_dir/deploy.sh" --env
reject 'Specify --env once' bash "$script_dir/deploy.sh" --env staging --env production
reject 'Production does not accept' bash "$script_dir/deploy.sh" --ref v0.4.0
reject 'Staging requires' bash "$script_dir/deploy.sh" --env staging
reject 'Staging requires' bash "$script_dir/deploy.sh" --env staging --ref dev
reject 'Staging requires' bash "$script_dir/deploy.sh" --env staging --ref abc1234
reject 'Staging requires' bash "$script_dir/deploy.sh" --env staging --ref v01.2.3
reject 'Use --ref' bash "$script_dir/deploy.sh" v0.4.0 --env staging --ref v0.4.0
reject 'Create a DB backup first' bash "$script_dir/deploy.sh" --env staging --ref v0.4.0 --apply
reject 'Specify --ref once' bash "$script_dir/deploy.sh" --env staging --ref v0.4.0 --ref v0.4.0

cd "$fixture"
git init -q
git config user.name 'Target test'
git config user.email 'target-test@example.invalid'
git config core.autocrlf false
mkdir -p backend frontend scripts/release
cp "$script_dir/version-state.jq" scripts/release/
jq -n '{current:"0.3.0",next:"0.4.0"}' > version.json
jq -n '{version:"0.3.0"}' > backend/package.json
cp backend/package.json frontend/package.json
git add .; git commit -qm 'Development candidate'
candidate=$(git rev-parse HEAD)
git update-ref refs/remotes/origin/dev "$candidate"
resolve_staging "$candidate"
[[ $commit == "$candidate" && $tag == candidate-0.4.0-* && $source_ref == refs/remotes/origin/dev ]] || fail 'Dev candidate'
git commit --allow-empty -qm 'Feature candidate'
feature=$(git rev-parse HEAD)
reject 'must belong' resolve_staging "$feature"
git update-ref refs/remotes/origin/feature/test "$feature"
resolve_staging "$feature"
[[ $source_ref == refs/remotes/origin/feature/test ]] || fail 'Feature candidate'
jq -n '{version:"0.4.0"}' > backend/package.json
cp backend/package.json frontend/package.json
git add .; git commit -qm 'Prepared release'
release=$(git rev-parse HEAD)
git tag v0.4.0
git update-ref refs/remotes/origin/main "$release"
resolve_staging v0.4.0
[[ $commit == "$release" && $tag == v0.4.0 ]] || fail 'Final tagged staging release'
git update-ref refs/remotes/origin/main "$candidate"
reject 'must match origin/main' resolve_staging v0.4.0
git update-ref refs/remotes/origin/dev "$release"
jq -n '{version:"9.9.9"}' > frontend/package.json
git add .; git commit -qm 'Invalid versions'
invalid=$(git rev-parse HEAD)
git update-ref refs/remotes/origin/dev "$invalid"
reject 'versions differ' resolve_staging "$invalid"
echo 'PASS: environment mappings, 11 input rejections, dev/feature/tag selection and 3 invalid release cases.'

#!/usr/bin/env bash
set -Eeuo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
source "$script_dir/deploy.sh"
mkdir -p "$script_dir/../../.temp"
fixture=$(mktemp -d "$script_dir/../../.temp/server-version-test-XXXXXX")
cd "$fixture"
git init -q
git config user.name 'Release test'
git config user.email 'release-test@example.invalid'
git config core.autocrlf false
mkdir -p backend frontend scripts/release
cp "$script_dir/version-state.jq" scripts/release/
jq -n '{current:"0.2.0",next:"0.3.0"}' > version.json
jq -n '{version:"0.3.0"}' > backend/package.json
cp backend/package.json frontend/package.json
git add .
git commit -qm 'Prepared release'
commit=$(git rev-parse HEAD)
git tag v0.3.0
resolve_release "$commit"
[[ $tag == v0.3.0 ]]
# A stale live worktree must not influence the selected release.
jq -n '{current:"8.0.0",next:"9.0.0"}' > version.json
resolve_release "$commit" v0.3.0
if (resolve_release "$commit" v0.4.0) 2>/dev/null; then echo 'Wrong tag accepted'; exit 1; fi
git tag -d v0.3.0 >/dev/null
if (resolve_release "$commit") 2>/dev/null; then echo 'Missing tag accepted'; exit 1; fi
git restore version.json
git commit --allow-empty -qm 'Another commit'
git tag v0.3.0
if (resolve_release "$commit") 2>/dev/null; then echo 'Wrong commit accepted'; exit 1; fi
git tag -d v0.3.0 >/dev/null
jq -n '{version:"0.2.0"}' > backend/package.json
cp backend/package.json frontend/package.json
git add .
git commit -qm 'Unprepared packages'
git tag v0.3.0
if (resolve_release "$(git rev-parse HEAD)") 2>/dev/null; then echo 'Unprepared version accepted'; exit 1; fi
echo '6 server version scenarios passed (disposable local repository only).'

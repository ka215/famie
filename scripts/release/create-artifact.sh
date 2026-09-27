#!/usr/bin/env bash
set -Eeuo pipefail

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
[[ $# == 2 ]] || fail 'Usage: create-artifact.sh X.Y.Z OUTPUT_DIRECTORY'
version=$1
output=$2
[[ $version =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]] || fail 'Version must be stable X.Y.Z.'

repo=$(git rev-parse --show-toplevel)
cd "$repo"
for tool in git jq tar sha256sum find sort xargs; do command -v "$tool" >/dev/null || fail "Missing tool: $tool"; done
[[ -f frontend/.output/public/index.html ]] || fail 'Build frontend before creating an artifact.'
node scripts/release/verify-static-assets.mjs frontend/.output/public

metadata=$(jq -ser -f scripts/release/version-state.jq version.json backend/package.json frontend/package.json)
IFS=$'\t' read -r current next state <<< "$metadata"
[[ $next == "$version" && $state == next ]] || fail "Prepared package versions must equal $version."

commit=$(git rev-parse HEAD)
tree=$(git rev-parse 'HEAD^{tree}')
short_tree=${tree:0:12}
name="famie-v${version}-${short_tree}"
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
mkdir -p "$work/payload/frontend" "$work/bundle" "$output"

cp -a frontend/.output/public "$work/payload/frontend/public"
git archive HEAD backend | tar -xf - -C "$work/payload"
(
  cd "$work/payload"
  find backend frontend -type f -print0 | sort -z | xargs -0 sha256sum > checksums.sha256
)
tar -C "$work/payload" -czf "$work/bundle/payload.tar.gz" .
payload_sha=$(sha256sum "$work/bundle/payload.tar.gz" | cut -d' ' -f1)
database_rollback_compatible=true
if git rev-parse --verify HEAD^ >/dev/null 2>&1 && ! git diff --quiet HEAD^ HEAD -- backend/database/migrations; then
  database_rollback_compatible=false
fi
workflow_run_id=${GITHUB_RUN_ID:-0}
created_at=$(date -u +'%Y-%m-%dT%H:%M:%SZ')
jq -n \
  --arg version "$version" \
  --arg commit "$commit" \
  --arg tree "$tree" \
  --arg payload_sha "$payload_sha" \
  --arg created_at "$created_at" \
  --argjson workflow_run_id "$workflow_run_id" \
  --argjson database_rollback_compatible "$database_rollback_compatible" \
  '{schema_version:1,release_version:$version,source_commit:$commit,source_tree:$tree,payload_sha256:$payload_sha,workflow_run_id:$workflow_run_id,created_at:$created_at,frontend_version:$version,backend_version:$version,database_rollback_compatible:$database_rollback_compatible}' \
  > "$work/bundle/manifest.json"
(cd "$work/bundle" && sha256sum manifest.json > manifest.sha256)

archive="$output/$name.tar.gz"
tar -C "$work/bundle" -czf "$archive" .
(cd "$output" && sha256sum "$(basename "$archive")" > "$(basename "$archive").sha256")
cp "$work/bundle/manifest.json" "$output/$name.manifest.json"
printf '%s\n' "$name" > "$output/artifact-name.txt"
printf 'Created %s\n' "$archive"

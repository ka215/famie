#!/usr/bin/env bash

# Compare the complete candidate against the published release, not its parent.
# A missing baseline or Git error must never authorize a code-only rollback.
database_rollback_compatibility() {
  local published_version=$1 baseline
  baseline=$(git rev-parse --verify "refs/tags/v${published_version}^{commit}" 2>/dev/null) || {
    printf 'false\n'
    return 0
  }
  if git diff --quiet "$baseline" HEAD -- backend/database/migrations; then
    printf 'true\n'
  else
    printf 'false\n'
  fi
}

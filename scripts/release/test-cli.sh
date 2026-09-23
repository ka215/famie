#!/usr/bin/env bash
# Local runtime checks only; never invokes the deployment entry point.
set -Eeuo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/deploy-cli.sh"
repo_root=$(git rev-parse --show-toplevel)
mkdir -p "$repo_root/.temp"
fixture=$(mktemp -d "$repo_root/.temp/deploy-cli-test.XXXXXX")
fixture=$(realpath "$fixture")
if [[ $fixture == [A-Za-z]:/* ]]; then fixture=$(cygpath -u "$fixture"); fi
runtime=$(type -P php)
runtime=$(realpath "$runtime")
printf '%s\n' '<?php if (PHP_SAPI !== "cli" || $argv[1] !== "--version") { exit(1); } echo "Composer fixture OK\n";' > "$fixture/composer fixture.php"
printf '%s\n' '#!/usr/bin/env bash' 'echo cgi-fcgi' > "$fixture/cgi"
printf '%s\n' '#!/usr/bin/env bash' 'exit 0' > "$fixture/composer-wrapper"
chmod +x "$fixture/cgi"
(PHPCLI="$runtime"; COMPOSER_FILE="$fixture/composer fixture.php"; initialize_cli)
reject() {
  local php_path=$1 composer_path=$2 expected=$3 output
  if output=$(PHPCLI="$php_path"; COMPOSER_FILE="$composer_path"; initialize_cli 2>&1); then
    fail "Expected rejection: $expected"
  fi
  [[ $output == *"$expected"* ]] || fail "Unexpected rejection: $output"
}
reject "$fixture/missing" "$fixture/composer fixture.php" 'absolute executable path'
reject "$fixture/cgi" "$fixture/composer fixture.php" 'not CGI/FastCGI'
reject "$runtime" "$fixture/missing" 'readable Composer'
reject "$runtime" "$fixture/composer-wrapper" 'Composer is a wrapper'
printf '%s\n' '<?php exit(1);' > "$fixture/broken.php"
reject "$runtime" "$fixture/broken.php" 'Composer could not run'
echo 'CLI initialization: success and 5 rejection cases passed.'

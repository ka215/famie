#!/usr/bin/env bash
set -Eeuo pipefail

# GitHub-hosted Ubuntuの一時環境だけを対象とする。
[[ ${GITHUB_ACTIONS:-} == true && ${RUNNER_OS:-} == Linux ]] || {
  echo 'Run this script on a GitHub Actions Linux runner.' >&2
  exit 1
}
[[ $# -gt 0 ]] || { echo 'Specify the Playwright browsers.' >&2; exit 1; }

# 解決不能な配布先へAPTが長時間再試行する前に、既知のミラーを選ぶ。
mirror=archive.ubuntu.com
if ! timeout 15 getent ahostsv4 "$mirror" >/dev/null; then
  mirror=azure.archive.ubuntu.com
  if ! timeout 15 getent ahostsv4 "$mirror" >/dev/null; then
    echo 'Neither Ubuntu mirror could be resolved; dependency installation was not started.' >&2
    exit 1
  fi
fi
printf 'Playwright dependency mirror: %s\n' "$mirror"

# security.ubuntu.com・署名設定は変更しない。
for source in /etc/apt/apt-mirrors.txt /etc/apt/sources.list /etc/apt/sources.list.d/ubuntu.sources; do
  if [[ -f "$source" ]]; then
    sudo sed -i "s|https\\?://\\(azure\\.\\)\\?archive\\.ubuntu\\.com/ubuntu|https://$mirror/ubuntu|g" "$source"
  fi
done

sudo tee /etc/apt/apt.conf.d/99famie-ci-network >/dev/null <<'APT'
Acquire::Retries "2";
Acquire::http::Timeout "20";
Acquire::https::Timeout "20";
APT

# 無通信時のAPT timeoutとは別に、workflow側でも工程全体を8分に制限する。
for attempt in 1 2; do
  if pnpm exec playwright install-deps "$@"; then
    exit 0
  else
    status=$?
  fi
  if [[ $attempt == 2 ]]; then
    echo "Playwright OS dependency installation failed after $attempt attempts." >&2
    exit "$status"
  fi
  echo 'Retrying Playwright OS dependencies once in 5 seconds...' >&2
  sleep 5
done

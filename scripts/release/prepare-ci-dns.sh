#!/usr/bin/env bash
set -Eeuo pipefail

[[ ${GITHUB_ACTIONS:-} == true && ${RUNNER_OS:-} == Linux && ${RUNNER_ENVIRONMENT:-} == github-hosted ]] || {
  echo 'DNS preparation is restricted to disposable GitHub-hosted Linux runners.' >&2
  exit 1
}

hosts=(archive.ubuntu.com azure.archive.ubuntu.com apt.postgresql.org github.com)
healthy=1
for host in "${hosts[@]}"; do
  if ! timeout 15 getent ahostsv4 "$host" >/dev/null; then healthy=0; break; fi
done
if ((healthy)); then echo 'Configured runner DNS is healthy; no changes.'; exit 0; fi

# Validate the replacement directly before changing this job's system resolver.
timeout 25 node "$(dirname "$0")/diagnose-ci-dns.mjs" --check-cloudflare
command -v resolvectl >/dev/null
systemctl is-active --quiet systemd-resolved
interface=$(ip -4 -o route show default | awk '{ for (i=1; i<NF; i++) if ($i == "dev") { print $(i+1); exit } }')
[[ $interface =~ ^[[:alnum:]_.-]+$ ]] || { echo 'Cannot identify runner DNS interface.' >&2; exit 1; }

# Runtime settings only. The dedicated VM and these settings expire with this job.
sudo resolvectl dns "$interface" 1.1.1.1
sudo resolvectl domain "$interface" '~.'
sudo resolvectl flush-caches
for host in "${hosts[@]}"; do
  timeout 15 getent ahostsv4 "$host" >/dev/null || { echo 'Runner DNS override verification failed.' >&2; exit 1; }
done
echo 'Runner DNS verified with Cloudflare for this disposable job.'

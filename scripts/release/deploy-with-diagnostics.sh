#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
mode=${1:-}
[[ $mode == run || $mode == collect ]] || exit 1
[[ $GITHUB_RUN_ID =~ ^[0-9]+$ && $GITHUB_RUN_ATTEMPT =~ ^[0-9]+$ ]] || exit 1
[[ $DEPLOY_ENVIRONMENT == staging || $DEPLOY_ENVIRONMENT == production ]] || exit 1
directory="$RUNNER_TEMP/famie-deployment-diagnostics-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"
raw="$RUNNER_TEMP/famie-ssh/deployment-debug-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT.log"
mkdir -p -- "$directory"
ssh_options=(-i "$ssh_key" -o "UserKnownHostsFile=$known_hosts" -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=3)
if [[ $mode == run ]]; then
  apply=()
  if [[ $DEPLOY_APPLY == true ]]; then apply=(--apply); fi
  printf 'event=ssh_started\n' > "$directory/client.log"
  result=0
  ssh -vv -E "$raw" "${ssh_options[@]}" "$DEPLOY_USER@$DEPLOY_HOST" \
    "export PATH=\"\$HOME/bin:\$PATH\"; chmod 700 \"\$HOME/famie-release-incoming/$remote\"/*.sh; \"\$HOME/famie-release-incoming/$remote/supervise-deployment.sh\" --env '$DEPLOY_ENVIRONMENT' --bundle \"\$HOME/famie-release-incoming/$remote/$(basename "$archive")\" --checksum \"\$HOME/famie-release-incoming/$remote/$(basename "$checksum")\" ${apply[*]}" || result=$?
  printf 'event=ssh_finished exit=%s\n' "$result" >> "$directory/client.log"
  exit "$result"
fi

# Raw SSH diagnostics stay in the runner's private temp directory, never artifacts.
summary="$directory/ssh-summary.log"
: > "$summary"
if [[ -f $raw ]]; then
  grep -E '^debug[123]: (Exit status -?[0-9]+|client_input_channel_req: channel [0-9]+ rtype exit-(status|signal) reply [0-9]+)$' "$raw" > "$summary" || true
  if ! grep -q 'rtype exit-status ' "$summary"; then printf 'event=no_remote_exit_status\n' >> "$summary"; fi
else
  printf 'event=ssh_debug_unavailable\n' >> "$summary"
fi
result=0
ssh "${ssh_options[@]}" "$DEPLOY_USER@$DEPLOY_HOST" \
  "for name in supervisor deploy backup; do file=\"\$HOME/famie-release-incoming/$remote/diagnostics/\$name.log\"; if test -f \"\$file\" && test ! -L \"\$file\"; then cat \"\$file\"; fi; done" \
  > "$RUNNER_TEMP/famie-ssh/deployment-server-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT.log" \
  2> "$RUNNER_TEMP/famie-ssh/deployment-collect-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT.log" || result=$?
printf 'event=server_log_collection exit=%s\n' "$result" >> "$directory/client.log"
# Allow only the structured schema; even unexpected server output cannot leak values.
grep -E '^at=[0-9T:+-]+ component=(supervisor|deploy|backup) event=[a-z_]+ stage=[a-z_]+ pid=[0-9]+ ppid=[0-9]+ exit=[0-9]+ line=[0-9]+ signal=(none|HUP|INT|TERM)$' \
  "$RUNNER_TEMP/famie-ssh/deployment-server-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT.log" > "$directory/server.log" || true
if [[ ! -s $directory/server.log ]]; then printf 'event=server_logs_unavailable\n' >> "$directory/client.log"; fi

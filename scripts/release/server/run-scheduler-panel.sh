#!/bin/sh
set -eu

[ "$#" -eq 1 ] || exit 1
case "$1" in
  staging) app=famie-stg ;;
  production) app=famie ;;
  *) exit 1 ;;
esac

PATH="$HOME/bin:/usr/local/bin:/usr/bin:/bin"
FAMIE_SCHEDULER_DELAY_SECONDS=0
export PATH FAMIE_SCHEDULER_DELAY_SECONDS
exec /usr/bin/bash "$HOME/$app/backend/storage/app/operations/run-scheduler.sh" "$1"

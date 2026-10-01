#!/usr/bin/env bash
set -Eeuo pipefail

REMOTE_PID_FILE="${DEV_REMOTE_PID_FILE:-/tmp/vm-manager-subs-optimizer-dev.pid}"
REMOTE_READY_FILE="${DEV_REMOTE_READY_FILE:-/tmp/vm-manager-subs-optimizer-dev.ready}"
REMOTE_CANCEL_FILE="${DEV_REMOTE_CANCEL_FILE:-/tmp/vm-manager-subs-optimizer-dev.cancel}"
services_script="${1:-scripts/dev-services.sh}"

clear_state() {
    rm -f "$REMOTE_PID_FILE" "$REMOTE_READY_FILE" "$REMOTE_CANCEL_FILE"
}

trap clear_state EXIT

if [[ -e "$REMOTE_CANCEL_FILE" ]]; then
    exit 143
fi

echo "$$" > "$REMOTE_PID_FILE"
echo "$$" > "$REMOTE_READY_FILE"

if [[ -e "$REMOTE_CANCEL_FILE" ]]; then
    rm -f "$REMOTE_PID_FILE" "$REMOTE_READY_FILE"
    exit 143
fi

bash "$services_script"

#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
LOCK_PATH="${PROJECT_ROOT}/storage/framework/dev.lock"
LOCK_DIRECTORY="${LOCK_PATH}.directory"
REMOTE_RUN_ID="$$-$(date +%s)-${RANDOM}"
REMOTE_PID_FILE="/tmp/vm-manager-subs-optimizer-dev-${REMOTE_RUN_ID}.pid"
REMOTE_READY_FILE="/tmp/vm-manager-subs-optimizer-dev-${REMOTE_RUN_ID}.ready"
REMOTE_CANCEL_FILE="/tmp/vm-manager-subs-optimizer-dev-${REMOTE_RUN_ID}.cancel"
running_process=""
lock_mode=""

cd "$PROJECT_ROOT"
mkdir -p "${PROJECT_ROOT}/storage/framework"

has_application_key() {
    php -r '
        require "vendor/autoload.php";
        $values = Dotenv\Dotenv::createArrayBacked(getcwd(), ".env")->safeLoad();
        $key = $values["APP_KEY"] ?? null;
        exit(is_string($key) && trim($key) !== "" ? 0 : 1);
    '
}

ensure_application_key() {
    if ! has_application_key; then
        php artisan key:generate --force --no-interaction --ansi
    fi

    if ! has_application_key; then
        printf 'APP_KEY could not be initialized in .env.\n' >&2
        exit 1
    fi
}

release_lock() {
    if [[ "$lock_mode" == "mkdir" ]]; then
        rm -f "${LOCK_DIRECTORY}/pid"
        rmdir "${LOCK_DIRECTORY}" 2>/dev/null || true
    fi
}

install_lock_traps() {
    trap release_lock EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
}

acquire_lock() {
    if command -v flock >/dev/null 2>&1; then
        exec 9>"$LOCK_PATH"

        if ! flock -n 9; then
            printf 'The development environment is already running for this project.\n' >&2
            exit 1
        fi

        lock_mode="flock"
        install_lock_traps
        return
    fi

    if mkdir "$LOCK_DIRECTORY" 2>/dev/null; then
        printf '%s\n' "$$" > "${LOCK_DIRECTORY}/pid"
        lock_mode="mkdir"
        install_lock_traps
        return
    fi

    printf 'The development environment is already running for this project. If no process is active, remove %s and try again.\n' "$LOCK_DIRECTORY" >&2
    exit 1
}

stop_remote_process() {
    docker compose exec --user sail -T \
        --env "DEV_REMOTE_PID_FILE=${REMOTE_PID_FILE}" \
        --env "DEV_REMOTE_READY_FILE=${REMOTE_READY_FILE}" \
        --env "DEV_REMOTE_CANCEL_FILE=${REMOTE_CANCEL_FILE}" \
        laravel.test bash -s <<'REMOTE_CLEANUP' >/dev/null 2>&1 || true
set +e

pid_file="${DEV_REMOTE_PID_FILE:-/tmp/vm-manager-subs-optimizer-dev.pid}"
ready_file="${DEV_REMOTE_READY_FILE:-/tmp/vm-manager-subs-optimizer-dev.ready}"
cancel_file="${DEV_REMOTE_CANCEL_FILE:-/tmp/vm-manager-subs-optimizer-dev.cancel}"
termination_confirmed=false

if [[ -s "$pid_file" ]]; then
    pid="$(<"$pid_file")"
    args="$(ps -o args= -p "$pid" 2>/dev/null | sed 's/^[[:space:]]*//')"

    case "$args" in
        *dev-remote.sh*|*dev-services.sh*)
            descendants() {
                for child in $(pgrep -P "$1" 2>/dev/null); do
                    descendants "$child"
                done

                printf '%s\n' "$1"
            }

            process_ids="$(descendants "$pid")"

            for process_id in $process_ids; do
                kill -TERM "$process_id" 2>/dev/null || true
            done

            for _ in {1..50}; do
                running=false

                for process_id in $process_ids; do
                    if kill -0 "$process_id" 2>/dev/null; then
                        running=true
                        break
                    fi
                done

                [[ "$running" == false ]] && break
                sleep 0.1
            done

            for process_id in $process_ids; do
                kill -KILL "$process_id" 2>/dev/null || true
            done

            for _ in {1..10}; do
                running=false

                for process_id in $process_ids; do
                    if kill -0 "$process_id" 2>/dev/null; then
                        running=true
                        break
                    fi
                done

                [[ "$running" == false ]] && break
                sleep 0.1
            done

            [[ "$running" == false ]] && termination_confirmed=true
            ;;
    esac
fi

if [[ "$termination_confirmed" == true ]]; then
    rm -f "$pid_file" "$ready_file" "$cancel_file"
fi
REMOTE_CLEANUP
}

cancel_remote_process() {
    docker compose exec --user sail -T laravel.test touch "${REMOTE_CANCEL_FILE}" >/dev/null 2>&1 || true
}

remote_process_is_registered() {
    docker compose exec --user sail -T laravel.test test -s "${REMOTE_READY_FILE}" >/dev/null 2>&1
}

local_process_is_running() {
    local process_status

    [[ -n "$running_process" ]] || return 1
    process_status="$(ps -o stat= -p "$running_process" 2>/dev/null || true)"

    [[ -n "$process_status" && "$process_status" != Z* ]]
}

wait_for_remote_registration() {
    for _ in {1..100}; do
        if remote_process_is_registered; then
            return 0
        fi

        if ! local_process_is_running; then
            return 1
        fi

        sleep 0.1
    done

    return 1
}

cleanup() {
    local exit_code=$?

    trap - EXIT INT TERM

    if [[ -n "$running_process" ]]; then
        cancel_remote_process
        wait_for_remote_registration || true
    fi
    stop_remote_process

    if [[ -n "$running_process" ]]; then
        kill -TERM "$running_process" 2>/dev/null || true
        wait "$running_process" 2>/dev/null || true
    fi
    release_lock

    exit "$exit_code"
}

acquire_lock

if [[ ! -f .env ]]; then
    cp .env.example .env
fi

ensure_application_key

if ! command -v docker >/dev/null 2>&1; then
    printf 'Docker is required. Start Docker Desktop or the Docker service and try again.\n' >&2
    exit 1
fi

if ! docker info >/dev/null 2>&1; then
    printf 'Docker is not running. Start Docker Desktop or the Docker service and try again.\n' >&2
    exit 1
fi

export WWWUSER="${WWWUSER:-$(id -u)}"
export WWWGROUP="${WWWGROUP:-$(id -g)}"

docker compose up -d --wait
docker compose exec --user root -T laravel.test chown -R sail:sail storage bootstrap/cache
stop_remote_process
docker compose exec --user sail -T laravel.test php artisan migrate --force --no-interaction

trap cleanup EXIT

remote_command='exec bash scripts/dev-remote.sh'
printf -v quoted_remote_command '%q' "$remote_command"

docker compose exec --user sail -T \
    --env "DEV_REMOTE_PID_FILE=${REMOTE_PID_FILE}" \
    --env "DEV_REMOTE_READY_FILE=${REMOTE_READY_FILE}" \
    --env "DEV_REMOTE_CANCEL_FILE=${REMOTE_CANCEL_FILE}" \
    laravel.test bash -lc "setsid --wait bash -lc ${quoted_remote_command}" &
running_process="$!"

if ! wait_for_remote_registration; then
    printf 'The development process could not register inside the container.\n' >&2
    exit 1
fi

if wait "$running_process"; then
    exit_code=0
else
    exit_code=$?
fi

printf 'The development process stopped (exit code %s).\n' "$exit_code" >&2
exit "$exit_code"

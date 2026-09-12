#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
LOCK_PATH="${PROJECT_ROOT}/storage/framework/dev.lock"
LOCK_DIRECTORY="${LOCK_PATH}.directory"
lock_mode=""

cd "$PROJECT_ROOT"
mkdir -p "${PROJECT_ROOT}/storage/framework"

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

has_application_key() {
    php -r '
        require "vendor/autoload.php";
        $values = Dotenv\Dotenv::createArrayBacked(getcwd(), ".env")->safeLoad();
        $key = $values["APP_KEY"] ?? null;
        exit(is_string($key) && trim($key) !== "" ? 0 : 1);
    '
}

acquire_lock

if [[ ! -f .env ]]; then
    cp .env.example .env
fi

if ! has_application_key; then
    php artisan key:generate --force --no-interaction --ansi
fi

if ! has_application_key; then
    printf 'APP_KEY could not be initialized in .env.\n' >&2
    exit 1
fi

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

npm install
npm run build
docker compose up -d --wait
docker compose exec --user root -T laravel.test chown -R sail:sail storage bootstrap/cache
docker compose exec --user sail -T laravel.test php artisan migrate --force --no-interaction

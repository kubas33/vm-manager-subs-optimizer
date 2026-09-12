#!/usr/bin/env bash
set -Eeuo pipefail

npx --no-install concurrently --raw --kill-others \
    "php artisan queue:listen --name=vm-manager-subs-optimizer-dev --tries=1 --timeout=0" \
    "php artisan pail --timeout=0" \
    "npm run dev -- --mode vm-manager-subs-optimizer-dev --host 0.0.0.0"

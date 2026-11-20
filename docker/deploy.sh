#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
cd "$(dirname "$0")/.."

candidate=${1:?Usage: bash docker/deploy.sh candidate.env [--initialize]}
initialize=${2:-}
app_env=${APP_ENV_FILE:-.env.prod}
[[ -f "$candidate" && -f "$app_env" ]] || { echo 'Release and application environment files are required.' >&2; exit 1; }
[[ "$initialize" == '' || "$initialize" == --initialize ]] || exit 1
[[ ! -e release.env || ! "$candidate" -ef release.env ]] || { echo 'Keep the candidate separate from the active release.env.' >&2; exit 1; }

# Read dotenv as data, never execute it as a shell script.
for key in RELEASE_SHA APP_IMAGE WEB_IMAGE; do
    value=$(sed -n "s/^${key}=//p" "$candidate")
    if [[ "$key" == RELEASE_SHA ]]; then
        [[ "$value" =~ ^[a-f0-9]{40}$ ]] || { echo 'Invalid release SHA.' >&2; exit 1; }
    else
        [[ "$value" =~ ^ghcr\.io/[a-z0-9._/-]+@sha256:[a-f0-9]{64}$ ]] || { echo 'Release images must use GHCR digests.' >&2; exit 1; }
    fi
done

if [[ "$initialize" == --initialize ]]; then
    [[ ! -e release.env ]] || { echo 'An active release already exists.' >&2; exit 1; }
else
    [[ -f release.env ]] || { echo 'Use --initialize for a new empty installation.' >&2; exit 1; }
fi
mkdir .deploy.lock || { echo 'Another deployment is running (.deploy.lock).' >&2; exit 1; }
dc() { env -u APP_IMAGE -u WEB_IMAGE docker compose --env-file "$app_env" --env-file "$candidate" -f compose.prod.yaml "$@"; }
maintenance=0
finish() {
    code=$?
    if (( code != 0 && maintenance == 1 )); then
        dc exec -T app php artisan down >/dev/null 2>&1 || true
        dc stop worker scheduler >/dev/null 2>&1 || true
        echo 'Deployment failed; maintenance is retained. Inspect logs and the backup before recovery.' >&2
    fi
    rmdir .deploy.lock
    exit "$code"
}
trap finish EXIT

dc pull app web
dc up -d --wait --wait-timeout 180 --no-recreate db
if [[ "$initialize" == --initialize ]]; then
    read -r -p 'Administrator email: ' ADMIN_EMAIL
    read -r -s -p 'Administrator password (at least 12 characters): ' ADMIN_PASSWORD
    printf '\n'
    export ADMIN_EMAIL ADMIN_PASSWORD
    dc run --rm --no-deps -e ADMIN_EMAIL -e ADMIN_PASSWORD app php docker/install.php
    unset ADMIN_EMAIL ADMIN_PASSWORD
else
    maintenance=1
    dc exec -T app php artisan down
    dc stop worker scheduler app
    bash docker/backup.sh release.env
    dc run --rm --no-deps app php artisan migrate --force --no-interaction
fi
dc up -d --force-recreate --wait --wait-timeout 180 app web
dc exec -T app php artisan up
dc exec -T web wget -q -O /dev/null http://127.0.0.1:8080/up
dc up -d worker scheduler
if [[ -f release.env ]]; then cp release.env release.previous.env; fi
cp "$candidate" release.env
chmod 600 release.env
maintenance=0
printf 'Deployment completed. Active release: %s\n' "$(sed -n 's/^RELEASE_SHA=//p' release.env)"

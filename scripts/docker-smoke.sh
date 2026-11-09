#!/usr/bin/env bash
# Smoke test of the Docker stack (docker-compose.yml, docs/architecture.md
# §10), run by the `docker` job in .github/workflows/ci.yml and by hand from a
# copy of the repository:
#
#   scripts/docker-smoke.sh
#
# Without a .env it writes one for http://localhost:${ELIFBA_SMOKE_PORT:-8088}
# (scripts/docker-env.sh). It builds and starts the stack, then checks: every
# service healthy; /up through Caddy; the login page with built assets and no
# Vite dev server; a WebSocket handshake to Reverb through Caddy; Reverb's HTTP
# API not reachable from outside but reachable from `app`; the scheduler's
# four tasks; a backup dump; data surviving `down` and `up`.
# ELIFBA_SMOKE_DOWN=1 removes the stack and its volumes at the end.

set -euo pipefail

cd "$(dirname "$0")/.."

failures=0
pass() { printf '  \033[32mok\033[0m   %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; failures=$((failures + 1)); }
step() { printf '\n== %s\n' "$1"; }
check() { # check <description> <command...>
    local description=$1
    shift
    if "$@"; then pass "$description"; else fail "$description"; fi
}

if [ ! -f .env ]; then
    step "Writing .env for an http try-out"
    scripts/docker-env.sh localhost http "${ELIFBA_SMOKE_PORT:-8088}"
fi

# One value from .env, without its quotes.
env_value() {
    grep -E "^$1=" .env | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

domain=$(env_value ELIFBA_DOMAIN)
scheme=$(env_value ELIFBA_SCHEME)
port=$(env_value ELIFBA_PORT)
reverb_key=$(env_value REVERB_APP_KEY)
reverb_id=$(env_value REVERB_APP_ID)
base="$scheme://$domain:$port"

compose() { docker compose "$@"; }
status_of() { # status_of <url> [curl options...]
    local url=$1
    shift
    curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$@" "$url" || true
}

step "Building and starting the stack for $base"
compose up -d --build --wait --wait-timeout 300

step "Services"
for service in proxy app reverb scheduler db backup; do
    health=$(docker inspect --format '{{.State.Health.Status}}' "$(compose ps -q "$service")" 2>/dev/null || echo missing)
    check "$service is $health" test "$health" = healthy
done

step "HTTP through Caddy"
check "/up answers 200" test "$(status_of "$base/up")" = 200

login=$(curl -fsS --max-time 10 "$base/login" || true)
asset=$(grep -oE '/build/assets/[^"]+\.js' <<<"$login" | head -n 1 || true)
check "the login page names a built script ($asset)" test -n "$asset"
check "the built script loads" test "$(status_of "$base$asset")" = 200
check "the page does not point at a Vite dev server" bash -c '! grep -q "@vite/client" <<<"$1"' _ "$login"
check "fonts come from the app itself" bash -c '! grep -qE "fonts\.(googleapis|bunny)" <<<"$1"' _ "$login"

step "Reverb through Caddy"
handshake() { # handshake <origin>: the status line and first frame of a WebSocket to /app/{key}
    curl -s -i --http1.1 --max-time 3 \
        -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
        -H 'Sec-WebSocket-Version: 13' -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
        -H "Origin: $1" \
        "$base/app/$reverb_key?protocol=7&client=js&version=8.4.0&flash=false" |
        LC_ALL=C tr -c '[:print:]\n' '.' || true
}
own=$(handshake "$base")
check "a WebSocket from the app's origin is switched (101)" grep -q '^HTTP/1.1 101' <<<"$own"
check "… and Reverb accepts it (pusher:connection_established)" grep -q 'pusher:connection_established' <<<"$own"
check "a WebSocket from another origin is refused (4009)" \
    grep -q '"code\\":4009' <<<"$(handshake http://elsewhere.example)"
check "Reverb's HTTP API is not public: GET /apps/… is 404" \
    test "$(status_of "$base/apps/$reverb_id/channels")" = 404
check "Reverb's HTTP API is not public: POST /apps/…/events is 404" \
    test "$(status_of "$base/apps/$reverb_id/events" -X POST -H 'Content-Type: application/json' -d '{}')" = 404
check "app reaches Reverb on the internal network" \
    compose exec -T app curl -fsS --max-time 5 -o /dev/null http://reverb:8080/up

step "The image"
check "no public/hot in the image" compose exec -T app test ! -e public/hot
check "no .env in the image" compose exec -T app test ! -e .env
check "public/content links to content/assets" compose exec -T app test -L public/content -a -d public/content/
check "content is compiled (content:cache)" compose exec -T app test -f bootstrap/cache/content.php

step "Scheduler"
schedule=$(compose exec -T scheduler php artisan schedule:list 2>&1 || true)
for task in classroom:prune-archived play:abandon-stale play:prune-old-games play:prune-guest-boards; do
    check "schedule:list has $task" grep -q "$task" <<<"$schedule"
done

step "Backup"
compose exec -T backup elifba-backup now
latest=$(compose exec -T backup sh -c 'ls -1t /backups/elifba-*.dump | head -n 1' | tr -d '\r')
check "a dump was written ($latest)" test -n "$latest"
check "the dump is a readable pg_dump archive" \
    bash -c 'docker compose exec -T backup pg_restore --list "$1" | grep -q "TABLE public migrations"' _ "$latest"

step "Data survives down and up"
psql() { compose exec -T db psql -U elifba -d elifba -tAc "$1"; }
psql "DELETE FROM cache WHERE key = 'elifba-docker-smoke'" >/dev/null
psql "INSERT INTO cache (key, value, expiration) VALUES ('elifba-docker-smoke', 'kept', 2147483647)" >/dev/null
compose down
compose up -d --wait --wait-timeout 300
check "the row written before down is there" test "$(psql "SELECT value FROM cache WHERE key = 'elifba-docker-smoke'")" = kept
check "the backup volume is there" compose exec -T backup test -s "$latest"
psql "DELETE FROM cache WHERE key = 'elifba-docker-smoke'" >/dev/null
check "/up answers 200 again" test "$(status_of "$base/up")" = 200

if [ "${ELIFBA_SMOKE_DOWN:-0}" = 1 ]; then
    step "Removing the stack and its volumes"
    compose down --volumes
fi

if [ "$failures" -gt 0 ]; then
    printf '\n%d check(s) failed.\n' "$failures"
    compose ps
    compose logs --tail 50
    exit 1
fi

printf '\nAll checks passed.\n'

#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
repository=$(cd ../.. && pwd)
scratch=$(mktemp -d)
mkdir "$scratch/spool"
image=tracewick-frankenphp-smoke:1.14.0

cleanup() {
    if [[ -n ${container:-} ]]; then
        if [[ $1 != 0 ]]; then
            docker logs "$container" 2>&1 || true
            docker inspect --format '{{json .State}}' "$container" >&2 || true
        fi
        docker rm -f "$container" >/dev/null
    fi
    rm -rf "$scratch"
}
trap 'cleanup "$?"' EXIT

docker build --tag "$image" .
container=$(docker run --detach --publish 127.0.0.1::8080 \
    --user "$(id -u):$(id -g)" \
    --volume "$repository:/app:ro" \
    --volume "$scratch/spool:/spool" \
    --env TRACEWICK_SPOOL_DIR=/spool \
    --env TRACEWICK_FRANKENPHP_SMOKE=1 \
    --env TRACEWICK_ENABLED=1 \
    --env XDG_DATA_HOME=/tmp/caddy-data \
    --env XDG_CONFIG_HOME=/tmp/caddy-config \
    "$image" frankenphp run --config /app/examples/frankenphp/Caddyfile)
address=$(docker port "$container" 8080/tcp)
ready=0
for _ in {1..100}; do
    if curl --noproxy '*' --fail --silent --max-time 1 --output /dev/null "http://$address/__ready"; then
        ready=1
        break
    fi
    sleep 0.1
done
test "$ready" = 1

header() {
    awk -v key="$1:" 'tolower($1) == key { sub(/\r$/, "", $2); print $2 }' "$scratch/headers"
}

worker_id=
request() {
    local path=$1 expected_status=$2 expected_body=$3 expected_count=$4 status id
    status=$(curl --noproxy '*' --silent --show-error --max-time 8 \
        --dump-header "$scratch/headers" --output "$scratch/body" --write-out '%{http_code}' \
        "http://$address$path")
    test "$status" = "$expected_status"
    test "$(cat "$scratch/body")" = "$expected_body"
    id=$(header x-worker-id)
    [[ "$id" =~ ^[0-9a-f]{16}$ ]]
    worker_id=${worker_id:-$id}
    test "$id" = "$worker_id"
    test "$(header x-buffered-profiles)" = 1
    test "$(header x-collected-profiles)" = "$expected_count"
    printf '%s returned %s from worker %s with one buffered profile\n' "$path" "$status" "$id"
}

request '/items/42?token=private' 200 item 1
test -z "$(find "$scratch/spool" -name '*.spool' -print -quit)"
touch "$scratch/spool/allow-flush"
request '/items/fail' 500 'handler failed' 2
request '/missing/987?email=user@example.com' 404 'not found' 3
request '/health' 200 ok 4

docker stop --timeout 15 "$container" >/dev/null
test "$(docker inspect --format '{{.State.ExitCode}}' "$container")" = 0
test "$(cat "$scratch/spool/worker-stopped")" = "$worker_id"
php ../assert-worker-spool.php "$scratch/spool" tracewick-frankenphp

#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
repository=$(cd ../.. && pwd)
scratch=$(mktemp -d)
mkdir "$scratch/spool"
image=tracewick-swoole-smoke:6.3.0

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
    --env TRACEWICK_SWOOLE_SMOKE=1 \
    --env TRACEWICK_ENABLED=1 \
    "$image" php /app/examples/swoole/server.php)
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

request() {
    local path=$1 response=$2 body=$3
    curl --noproxy '*' --fail --silent --show-error --max-time 8 \
        --dump-header "$scratch/$response.headers" --output "$scratch/$response.body" \
        "http://$address$path"
    test "$(cat "$scratch/$response.body")" = "$body"
}
worker() {
    awk 'tolower($1) == "x-worker-pid:" { sub(/\r$/, "", $2); print $2 }' "$scratch/$1.headers"
}

request '/overlap/first?token=private' first first &
first_pid=$!
request '/overlap/second?email=user@example.com' second second &
second_pid=$!
wait "$first_pid"
wait "$second_pid"
worker_pid=$(worker first)
[[ "$worker_pid" =~ ^[0-9]+$ ]]
test "$(worker second)" = "$worker_pid"
echo 'Both requests overlapped in the same coroutine worker.'

# Let the previous ten-second window close, then pulse on normal request completion.
sleep 10.1
test "$(curl --noproxy '*' --silent --show-error --max-time 8 --output /dev/null \
    --write-out '%{http_code}' "http://$address/pulse")" = 404
# The response is sent before the pulse can write the closed window.
for _ in {1..30}; do
    if [[ -n $(find "$scratch/spool" -name '*.spool' -print -quit) ]]; then
        break
    fi
    sleep 0.1
done
php assert-spool.php "$scratch/spool" running

docker stop --timeout 12 "$container" >/dev/null
test "$(docker inspect --format '{{.State.ExitCode}}' "$container")" = 0
test "$(cat "$scratch/spool/worker-stopped")" = "$worker_pid"
php assert-spool.php "$scratch/spool" stopped

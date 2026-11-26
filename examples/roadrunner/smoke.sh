#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
rr_binary=${ROADRUNNER_BINARY:-rr}
scratch=$(mktemp -d)
export TRACEWICK_SPOOL_DIR="$scratch/spool"
export TRACEWICK_RR_SMOKE=1
export TRACEWICK_ENABLED=1
mkdir "$TRACEWICK_SPOOL_DIR"

cleanup() {
    if [[ -n ${server_pid:-} ]]; then
        kill -TERM "$server_pid" 2>/dev/null || true
        wait "$server_pid" || true
    fi
    if [[ ${1:-0} != 0 && -f "$scratch/server.log" ]]; then
        cat "$scratch/server.log"
    fi
    rm -rf "$scratch"
}
trap 'cleanup "$?"' EXIT

php -r 'exit(extension_loaded("excimer") ? 0 : 1);' || {
    echo 'The RoadRunner smoke test requires ext-excimer.' >&2
    exit 1
}
"$rr_binary" serve -c .rr.yaml > "$scratch/server.log" 2>&1 &
server_pid=$!
ready=0
for _ in {1..50}; do
    if (echo >/dev/tcp/127.0.0.1/17850) 2>/dev/null; then
        ready=1
        break
    fi
    kill -0 "$server_pid"
    sleep 0.1
done
test "$ready" = 1

worker_pid=
header() {
    awk -v key="$1:" 'tolower($1) == key { sub(/\r$/, "", $2); print $2 }' "$scratch/headers"
}

request() {
    local path=$1 expected_status=$2 expected_body=$3 expected_count=$4 status pid
    status=$(curl --silent --show-error --max-time 5 \
        --dump-header "$scratch/headers" --output "$scratch/body" --write-out '%{http_code}' \
        "http://127.0.0.1:17850$path")
    test "$status" = "$expected_status"
    test "$(cat "$scratch/body")" = "$expected_body"
    pid=$(header x-worker-pid)
    [[ "$pid" =~ ^[0-9]+$ ]]
    worker_pid=${worker_pid:-$pid}
    test "$pid" = "$worker_pid"
    test "$(header x-buffered-profiles)" = 1
    test "$(header x-collected-profiles)" = "$expected_count"
    printf '%s returned %s from worker %s with one buffered profile\n' "$path" "$status" "$pid"
}

request '/items/42?token=private' 200 item 1
request '/items/fail' 500 'handler failed' 2
request '/missing/987?email=user@example.com' 404 'not found' 3
request '/health' 200 ok 4

kill -TERM "$server_pid"
wait "$server_pid"
server_pid=
test "$(cat "$TRACEWICK_SPOOL_DIR/worker-stopped")" = "$worker_pid"

php <<'PHP'
<?php

require 'vendor/autoload.php';

$directory = getenv('TRACEWICK_SPOOL_DIR');
$files = glob($directory . '/*/*.spool');
$profiles = [];
foreach ($files as $file) {
    $profile = Tracewick\Sink\SpoolFile::fromBytes(file_get_contents($file));
    if ($profile->application !== 'tracewick-roadrunner' || $profile->payload === '') {
        throw new RuntimeException('Invalid RoadRunner spool profile.');
    }
    $profiles[] = $profile;
}
usort($profiles, static fn($left, $right): int => $left->startNs <=> $right->startNs);
$labels = array_map(static fn($profile): array => $profile->labels, $profiles);
if ($labels !== [['route' => 'items.show'], ['route' => 'items.failed'], [], ['route' => 'health.show']]) {
    throw new RuntimeException('Unexpected route labels: ' . json_encode($labels));
}

echo "Verified four isolated profiles and graceful worker shutdown.\n";
PHP

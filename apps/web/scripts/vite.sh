#!/usr/bin/env bash

set -u

vite_pid=''

cleanup() {
    rm -f public/hot
}

stop_vite() {
    if [ -n "$vite_pid" ]; then
        kill -TERM "$vite_pid" 2>/dev/null || true
    fi
}

trap cleanup EXIT
trap stop_vite TERM INT

until [ -x node_modules/.bin/vp ]; do
    sleep 1
done

bun run dev &
vite_pid=$!
wait "$vite_pid"

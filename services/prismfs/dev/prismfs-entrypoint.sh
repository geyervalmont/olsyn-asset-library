#!/bin/sh
# Runs prismfs, trusting an extra CA when one is mounted (dev TLS, private PKI),
# and unmounting cleanly on SIGTERM so the pod's shared volume is released.
set -eu

if [ -n "${PRISMFS_EXTRA_CA:-}" ] && [ -f "$PRISMFS_EXTRA_CA" ]; then
    cp "$PRISMFS_EXTRA_CA" /usr/local/share/ca-certificates/prismfs-extra.crt
    update-ca-certificates >/dev/null 2>&1 || true
fi

mountpoint_path="${PRISMFS_MOUNTPOINT:-/srv/prismfs}"
mkdir -p "$mountpoint_path"

prismfs_pid=""
cleanup() {
    trap - EXIT INT TERM
    fusermount3 -u "$mountpoint_path" 2>/dev/null \
        || fusermount3 -uz "$mountpoint_path" 2>/dev/null \
        || umount -l "$mountpoint_path" 2>/dev/null \
        || true
    if [ -n "$prismfs_pid" ]; then
        kill "$prismfs_pid" 2>/dev/null || true
        wait "$prismfs_pid" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM

prismfs --log-format "${PRISMFS_LOG_FORMAT:-json}" "$@" &
prismfs_pid=$!
wait "$prismfs_pid"

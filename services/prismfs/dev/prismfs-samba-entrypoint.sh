#!/bin/sh
set -eu

mountpoint_path="${PRISMFS_MOUNTPOINT:-/srv/prismfs}"
manifest_path="${PRISMFS_MANIFEST:-/etc/prismfs/namespace.yaml}"
share_name="${PRISMFS_SMB_SHARE:-prismfs}"
deny_prefix="${PRISMFS_DENY_PREFIX:-/private}"
prismfs_pid=""
samba_pid=""

cleanup() {
    trap - EXIT INT TERM
    if [ -n "$samba_pid" ]; then
        kill "$samba_pid" 2>/dev/null || true
        wait "$samba_pid" 2>/dev/null || true
    fi
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

mkdir -p "$mountpoint_path" /run/samba
prismfs samba-config \
    --mountpoint "$mountpoint_path" \
    --share-name "$share_name" \
    --guest-account root > /etc/samba/smb.conf
testparm -s /etc/samba/smb.conf >/dev/null

prismfs --log-format json mount \
    --mountpoint "$mountpoint_path" \
    --manifest "$manifest_path" \
    --deny-prefix "$deny_prefix" &
prismfs_pid=$!

attempt=0
until mountpoint -q "$mountpoint_path"; do
    if ! kill -0 "$prismfs_pid" 2>/dev/null; then
        wait "$prismfs_pid"
        exit $?
    fi
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 100 ]; then
        printf 'PrismFS did not mount %s within 10 seconds\n' "$mountpoint_path" >&2
        exit 1
    fi
    sleep 0.1
done

smbd --foreground --no-process-group --debug-stdout &
samba_pid=$!
wait "$samba_pid"

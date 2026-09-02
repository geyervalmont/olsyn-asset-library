#!/usr/bin/env bash
set -euo pipefail

prismfs_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose="$prismfs_root/dev/compose.sh"
mountpoint_path="${PRISMFS_MOUNTPOINT:-$prismfs_root/mnt}"

cleanup() {
    fusermount3 -u "$mountpoint_path" 2>/dev/null \
        || fusermount3 -uz "$mountpoint_path" 2>/dev/null \
        || true
    "$compose" down --volumes --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

test -r /dev/fuse && test -w /dev/fuse || {
    printf 'mount: /dev/fuse must be readable and writable by the current user\n' >&2
    exit 1
}

"$compose" up --detach --wait rustfs
"$compose" run --rm rustfs-init
s3_port="$($compose port rustfs 9000 | sed 's/.*://')"

export PRISMFS_S3_BUCKET="${PRISMFS_S3_BUCKET:-prismfs-dev}"
export AWS_ACCESS_KEY_ID="${RUSTFS_ACCESS_KEY:-prismfs-dev}"
export AWS_SECRET_ACCESS_KEY="${RUSTFS_SECRET_KEY:-prismfs-local-secret-change-me}"
export AWS_DEFAULT_REGION="${PRISMFS_S3_REGION:-us-east-1}"
export AWS_ENDPOINT="http://127.0.0.1:$s3_port"
export AWS_ALLOW_HTTP=true

mkdir -p "$mountpoint_path"
printf 'mount: PrismFS is available at %s; press Ctrl-C to stop\n' "$mountpoint_path"
cd "$prismfs_root"
set +e
cargo run --locked -p prismfs-server --bin prismfs -- mount \
    --mountpoint "$mountpoint_path" \
    --manifest dev/namespace.yaml \
    --deny-prefix "${PRISMFS_DENY_PREFIX:-/private}"
status=$?
set -e
if [ "$status" -eq 130 ] || [ "$status" -eq 143 ]; then
    exit 0
fi
exit "$status"

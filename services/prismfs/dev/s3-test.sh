#!/usr/bin/env bash
set -euo pipefail

prismfs_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose="$prismfs_root/dev/compose.sh"

cleanup() {
    "$compose" down --volumes --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

"$compose" up --detach --wait rustfs
"$compose" run --rm rustfs-init
s3_port="$($compose port rustfs 9000 | sed 's/.*://')"

export PRISMFS_S3_BUCKET="${PRISMFS_S3_BUCKET:-prismfs-dev}"
export AWS_ACCESS_KEY_ID="${RUSTFS_ACCESS_KEY:-prismfs-dev}"
export AWS_SECRET_ACCESS_KEY="${RUSTFS_SECRET_KEY:-prismfs-local-secret-change-me}"
export AWS_DEFAULT_REGION="${PRISMFS_S3_REGION:-us-east-1}"
export AWS_ENDPOINT="http://127.0.0.1:$s3_port"
export AWS_ALLOW_HTTP=true

cd "$prismfs_root"
cargo test -p prismfs-storage --test s3_compat -- --ignored

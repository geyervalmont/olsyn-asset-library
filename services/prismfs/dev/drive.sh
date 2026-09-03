#!/usr/bin/env bash
# docker compose wrapper for the drive server (dev/drive.compose.yaml).
set -euo pipefail

prismfs_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="${PRISMFS_DRIVE_ENV:-$prismfs_root/drive.env}"
if [ ! -f "$env_file" ]; then
    printf 'drive: %s is missing; copy drive.env.example and set the drive token\n' "$env_file" >&2
    exit 1
fi

exec docker compose \
    --project-name prismfs-drive \
    --project-directory "$prismfs_root" \
    --env-file "$env_file" \
    --file "$prismfs_root/dev/drive.compose.yaml" \
    "$@"

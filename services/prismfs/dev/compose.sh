#!/usr/bin/env bash
set -euo pipefail

prismfs_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
path_hash="$(printf '%s' "$prismfs_root" | sha256sum | cut -c1-8)"
project_name="prismfs-$path_hash"
compose_args=(
    docker compose
    --project-name "$project_name"
    --project-directory "$prismfs_root"
    --file "$prismfs_root/dev/compose.yaml"
)

if [ -f "$prismfs_root/.env" ]; then
    compose_args+=(--env-file "$prismfs_root/.env")
fi

exec "${compose_args[@]}" "$@"

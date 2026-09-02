#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

gerry down -f "$repo_root/gerrymander.yaml" || true
docker compose -f "$repo_root/apps/web/compose.yaml" --project-directory "$repo_root/apps/web" down
docker compose -f "$repo_root/infrastructure/local/compose.yaml" --project-directory "$repo_root/infrastructure/local" down

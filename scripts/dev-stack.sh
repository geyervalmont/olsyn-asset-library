#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
web_dir="$repo_root/apps/web"

"$repo_root/scripts/bootstrap.sh"

printf '\nLocal services:\n'
printf '  app      https://asset-library.test\n'
printf '  mail     https://mail.asset-library.test\n'
printf '  S3       https://s3.asset-library.test\n'
printf '  storage  https://storage.asset-library.test/rustfs/console/\n'
printf '  Vite     https://vite.asset-library.test\n\n'

exec docker compose -f "$web_dir/compose.yaml" --project-directory "$web_dir" \
    exec laravel.test npm run dev

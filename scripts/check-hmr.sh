#!/usr/bin/env bash

set -euo pipefail

vite_url='https://vite.asset-library.test'
app_url='https://asset-library.test/ui'

curl --fail --silent --show-error "$vite_url/@vite/client" >/dev/null
app_html="$(curl --fail --silent --show-error "$app_url")"

if ! grep --fixed-strings --quiet "$vite_url/@vite/client" <<< "$app_html"; then
    printf 'Laravel is not loading the Vite HMR client.\n' >&2
    exit 1
fi

printf 'Bun/Vite HMR is reachable and attached to Laravel.\n'

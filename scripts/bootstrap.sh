#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
web_dir="$repo_root/apps/web"
web_compose=(docker compose -f "$web_dir/compose.yaml" --project-directory "$web_dir")
infra_compose=(docker compose -f "$repo_root/infrastructure/local/compose.yaml" --project-directory "$repo_root/infrastructure/local")

say() { printf 'bootstrap: %s\n' "$*"; }

command -v docker >/dev/null || { printf 'Docker is required.\n' >&2; exit 1; }
command -v gerry >/dev/null || { printf 'Gerrymander is required.\n' >&2; exit 1; }

docker network inspect dev-proxy >/dev/null 2>&1 || docker network create dev-proxy >/dev/null

if [ ! -f "$web_dir/.env" ]; then
    say "creating apps/web/.env"
    cp "$web_dir/.env.example" "$web_dir/.env"
fi

if [ ! -f "$web_dir/vendor/autoload.php" ]; then
    say "installing PHP dependencies"
    docker run --rm --user "$(id -u):$(id -g)" \
        -e HOME=/tmp/laravel-home \
        -e COMPOSER_HOME=/tmp/composer \
        -v "$web_dir:/var/www/html" \
        -w /var/www/html \
        laravelsail/php84-composer:latest \
        composer install --ignore-platform-reqs --no-interaction
fi

if ! grep -Eq '^APP_KEY=base64:.+' "$web_dir/.env"; then
    say "generating Laravel application key"
    docker run --rm --user "$(id -u):$(id -g)" \
        -e HOME=/tmp/laravel-home \
        -v "$web_dir:/var/www/html" \
        -w /var/www/html \
        laravelsail/php84-composer:latest \
        php artisan key:generate --no-interaction
fi

say "starting RustFS and creating the PrismFS development bucket"
"${infra_compose[@]}" up -d --wait rustfs
"${infra_compose[@]}" run --rm rustfs-init

say "starting the Laravel Sail stack (building its image when missing)"
"${web_compose[@]}" up -d --wait

if ! "${web_compose[@]}" exec -T laravel.test test -d node_modules; then
    say "installing frontend dependencies"
    "${web_compose[@]}" exec -T laravel.test npm ci
fi

say "applying Laravel migrations"
"${web_compose[@]}" exec -T laravel.test php artisan migrate --force

say "applying Gerrymander routes"
gerry up -f "$repo_root/gerrymander.yaml"

say "ready"

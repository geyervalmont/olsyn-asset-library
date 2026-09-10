# Production image for the OPAL control plane.
#
# FrankenPHP in classic mode: a process per request, no resident application.
# Octane's worker mode would be faster, but this is an internal tool with a
# handful of people on it and heavy work already lives in queued jobs, so the
# class of bugs that comes with state surviving between requests buys us
# nothing. Switching later is small: add laravel/octane, then change the CMD to
# `php artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000`.
#
# One image serves four roles — web, Horizon, scheduler and Reverb — so the
# entrypoint holds nothing web-specific and each workload overrides the command.

# ---------------------------------------------------------------------------
# Stage 1: PHP base. 8.5 matches the development container.
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.5 AS php_base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        libpng-dev \
        libjpeg-dev \
        libwebp-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        libpq-dev \
        libmagickwand-dev \
        zip \
        unzip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# gd and imagick are both load-bearing: the preview renderer and the downscale
# worker read and write pixels through them.
RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        opcache \
        redis \
        intl \
        imagick

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ---------------------------------------------------------------------------
# Stage 2: Composer dependencies, so the asset stage can reuse vendor/.
# ---------------------------------------------------------------------------
FROM php_base AS php_deps

WORKDIR /app

COPY composer.json composer.lock ./

# --no-scripts because artisan is not here yet; discovery runs in the app stage.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# ---------------------------------------------------------------------------
# Stage 3: Vite assets.
#
# Debian rather than Alpine: package.json pins *-linux-x64-gnu binaries for
# rollup, lightningcss and tailwind oxide, which musl cannot load.
# ---------------------------------------------------------------------------
FROM oven/bun:1 AS assets

WORKDIR /app

COPY package.json bun.lock* ./

RUN bun install --frozen-lockfile

COPY resources ./resources
COPY vite.config.js ./

# Flux ships its CSS inside the composer package, and the stylesheet imports it.
COPY --from=php_deps /app/vendor ./vendor

# The websocket client is compiled into the bundle, so where the browser should
# connect is decided here, at build time, not by the pod's environment.
# The key is a public client identifier, the same shape as a Pusher app key:
# it is meant to be readable in the bundle, so BuildKit's warning about
# secrets in ARG does not apply. The Reverb *secret* never leaves the cluster.
ARG VITE_REVERB_APP_KEY=""
ARG VITE_REVERB_HOST="opal-ws.olsyn.com"
ARG VITE_REVERB_PORT="443"
ARG VITE_REVERB_SCHEME="https"

ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY} \
    VITE_REVERB_HOST=${VITE_REVERB_HOST} \
    VITE_REVERB_PORT=${VITE_REVERB_PORT} \
    VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

RUN bun run build

# ---------------------------------------------------------------------------
# Stage 4: The application.
# ---------------------------------------------------------------------------
# The USD toolbox, which packages a variant into a self-contained USDZ.
#
# Built from source rather than downloaded, because the toolbox publishes CI
# artifacts rather than releases, and an Actions artifact needs credentials and
# expires. A pinned commit is reproducible without either.
#
# Bookworm rather than trixie deliberately: its glibc is older than the runtime
# image's, and a binary built against older glibc runs on newer, not the other
# way round.
FROM rust:1-bookworm AS toolbox

ARG USD_TOOLBOX_REF=54f2007d9dbc612cea58a1d4d48da13900453931

RUN git clone https://github.com/geyervalmont/usd-toolbox.git /src \
    && cd /src \
    && git checkout "${USD_TOOLBOX_REF}" \
    && cargo build --locked --release -p usd-toolbox-cli \
    && strip target/release/usd-toolbox

FROM php_base AS app

# Links the package to this repository in the registry, which is what lets the
# repository's own Actions token push to it. Without the link the package
# belongs to whoever first pushed it by hand, and CI is denied.
LABEL org.opencontainers.image.source="https://github.com/geyervalmont/olsyn-asset-library"
LABEL org.opencontainers.image.description="Olsyn Parametric Asset Library"

WORKDIR /app

COPY . .
COPY --from=php_deps /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Invoked as a subprocess rather than linked: the toolbox releases on its own
# cadence, and a crash in it should cost one variant rather than the worker.
COPY --from=toolbox /src/target/release/usd-toolbox /usr/local/bin/usd-toolbox

# composer install ran with --no-scripts, so package discovery happens here.
RUN composer dump-autoload --optimize --classmap-authoritative --no-interaction \
    && php artisan package:discover --ansi

# Directories the framework writes to, whether or not the build context had them.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /app /data /config \
    && chmod -R 775 storage bootstrap/cache

RUN { \
        echo "opcache.enable=1"; \
        echo "opcache.memory_consumption=256"; \
        echo "opcache.interned_strings_buffer=16"; \
        echo "opcache.max_accelerated_files=20000"; \
        # The code never changes inside a running container: a deploy is a new
        # image, so revalidating timestamps is pure overhead.
        echo "opcache.validate_timestamps=0"; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Material sources are large images; the library accepts them whole.
RUN { \
        echo "upload_max_filesize=256M"; \
        echo "post_max_size=256M"; \
        echo "memory_limit=512M"; \
        echo "max_execution_time=120"; \
    } > /usr/local/etc/php/conf.d/opal.ini

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

# Port 8000 rather than 80 so the server needs no privileged bind and the
# container can run as www-data. TLS is terminated at the ingress.
ENV SERVER_NAME=":8000" \
    SERVER_ROOT="/app/public"

USER www-data

EXPOSE 8000

ENTRYPOINT ["docker-entrypoint"]

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

#!/bin/sh
set -e

# Caching happens here, not at build time, because every setting arrives as an
# environment variable from the pod's secret. A config cache baked into the
# image would freeze whatever the build environment happened to hold.
#
# This runs for every role in the deployment — web, Horizon, scheduler and
# Reverb — so it must stay free of anything web-specific.

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"

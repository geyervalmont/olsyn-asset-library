#!/usr/bin/env bash
# Stage a locally downloaded corpus into the bucket the ingest reads from.
#
#   deploy/scripts/upload-corpus.sh "/path/to/Material Library"
#
# Resumable by design: rclone compares the destination first, so this can be
# interrupted and rerun as often as needed and will move only what is missing.
# Set BWLIMIT to keep the connection usable, e.g. BWLIMIT=20M for a working day.
#
# Point it at the "Material Library" folder itself. Its *contents* go directly
# under corpus/, because that is what the paths in the legacy database are
# relative to — an extra directory level makes all 40,330 lookups miss. The
# guard below exists because that mistake is invisible until ingest.
set -euo pipefail

SOURCE="${1:-}"
BUCKET="${BUCKET:-olsyn-prod-material-corpus}"
PREFIX="${PREFIX:-corpus}"

if [[ -z "$SOURCE" ]]; then
    echo "usage: $0 <path to the Material Library folder>" >&2
    exit 64
fi

if [[ ! -d "$SOURCE" ]]; then
    echo "error: $SOURCE is not a directory" >&2
    exit 66
fi

# The top-level category folders, as recorded in the legacy database. If none of
# them are directly inside the given path, it is the wrong level — almost always
# the parent of the folder that was meant.
found=0
for category in "Acoustic Panelling" Carpet Ceramic Fabric Leather Paint Stone Timber; do
    [[ -d "$SOURCE/$category" ]] && found=$((found + 1))
done

if (( found < 3 )); then
    echo "error: $SOURCE does not look like the Material Library folder." >&2
    echo "       Expected category folders (Fabric, Carpet, Ceramic, ...) directly inside it." >&2
    echo "       Found $found of 8. Point this at the folder itself, not its parent." >&2
    exit 65
fi

echo "staging $SOURCE -> s3://$BUCKET/$PREFIX"
echo "matched $found of 8 expected category folders"
echo

exec rclone copy "$SOURCE" ":s3:$BUCKET/$PREFIX" \
    --s3-provider=AWS \
    --s3-env-auth \
    --s3-region=ap-southeast-2 \
    --s3-no-check-bucket \
    --s3-chunk-size=16M \
    --s3-upload-concurrency=4 \
    --transfers=8 \
    --checkers=16 \
    --retries=5 \
    --low-level-retries=20 \
    --exclude=desktop.ini \
    --exclude=Thumbs.db \
    --exclude=.DS_Store \
    --exclude='~$*' \
    ${BWLIMIT:+--bwlimit="$BWLIMIT"} \
    --progress \
    --stats=30s

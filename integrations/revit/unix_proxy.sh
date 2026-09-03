#!/usr/bin/env bash
# The Revit extension's whole loop on a Unix box, against the real control plane
# and a live PrismFS mount — no Revit needed.
#
#   OPAL_TOKEN=<api token>  OPAL_DRIVE_TOKEN=<drive token>  ./unix_proxy.sh [variant code]
#
# Tokens: Settings → API tokens (web app) and Drives → issue token. Optional:
#   OPAL_API   (default https://asset-library.test)
#   OPAL_DRIVE (default studio-share)
#   OPAL_WORK  (default ./.unix-proxy; mount point and project live here)
#   OPAL_S3    S3 endpoint PrismFS reads from (default https://s3.asset-library.test)
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
API="${OPAL_API:-https://asset-library.test}"
DRIVE="${OPAL_DRIVE:-studio-share}"
WORK="${OPAL_WORK:-$HERE/.unix-proxy}"
VARIANT="${1:-CPT-TARKETT-ACADEMIX-ASHEN}"
PRISMFS="${PRISMFS_BIN:-$ROOT/services/prismfs/target/debug/prismfs}"

: "${OPAL_TOKEN:?set OPAL_TOKEN to an API token}"
: "${OPAL_DRIVE_TOKEN:?set OPAL_DRIVE_TOKEN to the drive token}"
[ -x "$PRISMFS" ] || { echo "build PrismFS first: (cd services/prismfs && cargo build)"; exit 1; }

MOUNT="$WORK/mnt"
PROJECT="$WORK/project.json"
LOG="$WORK/prismfs.log"
mkdir -p "$WORK"
rm -f "$PROJECT"

cleanup() { fusermount -u "$MOUNT" 2>/dev/null || true; }
trap cleanup EXIT
cleanup

echo "== mounting drive $DRIVE at $MOUNT"
set -a; source "$ROOT/services/prismfs/.env.example"; set +a
export AWS_ENDPOINT="${OPAL_S3:-https://s3.asset-library.test}"
"$PRISMFS" mount --mountpoint "$MOUNT" \
  --manifest-url "$API/prismfs/drives/$DRIVE/manifest.yaml" \
  --manifest-token "$OPAL_DRIVE_TOKEN" --refresh-interval 5 >"$LOG" 2>&1 &
for _ in $(seq 1 40); do mountpoint -q "$MOUNT" && break; sleep 0.25; done
mountpoint -q "$MOUNT" || { echo "mount failed:"; tail -5 "$LOG"; exit 1; }

CLI=(python3 "$HERE/opal_cli.py" --api "$API" --token "$OPAL_TOKEN" --mount "$MOUNT" --drive "$DRIVE")
echo "== files on the drive for $VARIANT";                "${CLI[@]}" check --variant "$VARIANT"
echo "== a project with one library material and one stranger"
"${CLI[@]}" seed --project "$PROJECT" --name "Proxy material" --id "proxy-$(date +%s)"
"${CLI[@]}" seed --project "$PROJECT" --name "Concrete - Generic"
echo "== sync before apply";                             "${CLI[@]}" sync --project "$PROJECT"
MATERIAL="$(python3 -c "import json;print(json.load(open('$PROJECT'))['materials'][0]['id'])")"
echo "== apply $VARIANT to $MATERIAL";                   "${CLI[@]}" apply --project "$PROJECT" --material "$MATERIAL" --variant "$VARIANT"
echo "== sync after apply (resolves through the written-back identity)"; "${CLI[@]}" sync --project "$PROJECT"
echo "== read the applied base colour through the mount and compare hashes"
python3 - "$PROJECT" <<'PY'
import hashlib, json, sys
project = json.load(open(sys.argv[1]))
path = project["materials"][0]["textures"]["base_color"]
digest = hashlib.sha256(open(path, "rb").read()).hexdigest()
print("  %s\n  sha256 %s" % (path, digest))
PY
echo "== PrismFS audit lines: $(grep -c 'filesystem access' "$LOG") (reads: $(grep -c '"read"' "$LOG"))"
echo "ok"

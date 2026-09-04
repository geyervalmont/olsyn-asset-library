#!/usr/bin/env bash
# The Revit extension's live half on a Unix box: mount a drive with PrismFS,
# link this machine to an OPAL account once, then stay connected and execute
# whatever the web app sends (Apply, Sync, Resolve) against a JSON project.
#
#   OPAL_DRIVE_TOKEN=<drive token> ./unix_agent.sh
#
# First run prompts a link code (or run `opal_cli.py link` beforehand); the
# token and realtime details are saved in ~/.config/opal/config.json.
# Optional: OPAL_API, OPAL_DRIVE, OPAL_WORK, OPAL_S3 as in unix_proxy.sh.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
API="${OPAL_API:-https://asset-library.test}"
DRIVE="${OPAL_DRIVE:-studio-share}"
WORK="${OPAL_WORK:-$HERE/.unix-proxy}"
PRISMFS="${PRISMFS_BIN:-$ROOT/services/prismfs/target/debug/prismfs}"

: "${OPAL_DRIVE_TOKEN:?set OPAL_DRIVE_TOKEN to the drive token}"
[ -x "$PRISMFS" ] || { echo "build PrismFS first: (cd services/prismfs && cargo build)"; exit 1; }

MOUNT="$WORK/mnt"
PROJECT="$WORK/agent-project.json"
LOG="$WORK/prismfs.log"
mkdir -p "$WORK"

cleanup() { fusermount -u "$MOUNT" 2>/dev/null || true; }
trap cleanup EXIT
cleanup

CLI=(python3 "$HERE/opal_cli.py" --api "$API" --drive "$DRIVE" --mount "$MOUNT")
if ! python3 -c "import sys; sys.path.insert(0, '$HERE'); from opal_client import Config; sys.exit(0 if Config().linked() else 1)"; then
  echo "== linking this machine to an OPAL account"
  "${CLI[@]}" link --no-browser
fi

echo "== mounting drive $DRIVE at $MOUNT"
set -a; source "$ROOT/services/prismfs/.env.example"; set +a
export AWS_ENDPOINT="${OPAL_S3:-https://s3.asset-library.test}"
"$PRISMFS" mount --mountpoint "$MOUNT" \
  --manifest-url "$API/prismfs/drives/$DRIVE/manifest.yaml" \
  --manifest-token "$OPAL_DRIVE_TOKEN" --refresh-interval 5 >"$LOG" 2>&1 &
for _ in $(seq 1 40); do mountpoint -q "$MOUNT" && break; sleep 0.25; done
mountpoint -q "$MOUNT" || { echo "mount failed:"; tail -5 "$LOG"; exit 1; }

if [ ! -f "$PROJECT" ]; then
  "${CLI[@]}" seed --project "$PROJECT" --name "Agent material" --id "agent-mat-1"
  "${CLI[@]}" seed --project "$PROJECT" --name "Concrete - Generic"
  "${CLI[@]}" seed --project "$PROJECT" --name "Carpet - Academix Ashen" --id "agent-mat-3"
fi

echo "== agent running; send commands from the web app (material page → Apply in …)"
"${CLI[@]}" agent --project "$PROJECT"

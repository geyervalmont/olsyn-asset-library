#!/usr/bin/env python3
"""Report on a corpus transfer that is running unattended.

The puller draws a progress bar, but a bar needs a terminal — redirect it to a
log for an overnight run and the output goes nearly silent. The ledger is the
real source of truth, so read that instead. Safe to run at any time: it opens
the database read-only and never blocks the transfer.

    deploy/scripts/corpus-status.py --manifest /path/to/material_assets.sqlite
    deploy/scripts/corpus-status.py --watch          # refresh every 30s
"""
import argparse
import os
import sqlite3
import subprocess
import sys
import time
from datetime import datetime, timezone

LEDGER = os.path.expanduser("~/.local/share/opal-corpus/ledger.sqlite")


def human(count: float) -> str:
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if count < 1024 or unit == "TB":
            return f"{count:,.1f} {unit}"
        count /= 1024


def duration(seconds: float) -> str:
    seconds = int(max(seconds, 0))
    hours, rest = divmod(seconds, 3600)
    minutes, secs = divmod(rest, 60)
    if hours:
        return f"{hours}h {minutes:02d}m"
    return f"{minutes}m {secs:02d}s" if minutes else f"{secs}s"


def running() -> tuple[bool, str]:
    found = subprocess.run(["pgrep", "-af", "pull-corpus.py"],
                           capture_output=True, text=True).stdout.strip()
    for line in found.splitlines():
        pid = line.split(maxsplit=1)[0]
        elapsed = subprocess.run(["ps", "-o", "etime=", "-p", pid],
                                 capture_output=True, text=True).stdout.strip()
        return True, f"pid {pid}, up {elapsed}"
    return False, "not running"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--ledger", default=LEDGER)
    parser.add_argument("--manifest", help="legacy database, for the true total")
    parser.add_argument("--window", type=int, default=10, help="minutes to average the rate over")
    parser.add_argument("--watch", action="store_true")
    parser.add_argument("--interval", type=int, default=30)
    args = parser.parse_args()

    expected_files = expected_bytes = None
    if args.manifest:
        with sqlite3.connect(f"file:{args.manifest}?mode=ro", uri=True) as manifest:
            expected_files, expected_bytes = manifest.execute(
                "SELECT COUNT(DISTINCT relative_path), SUM(bytes) FROM ("
                "  SELECT DISTINCT relative_path, bytes FROM asset_file"
                "  WHERE relative_path IS NOT NULL)").fetchone()

    while True:
        # Read-only, with a timeout rather than a hard failure: the transfer
        # commits every fifty files and would otherwise lock us out mid-write.
        ledger = sqlite3.connect(f"file:{args.ledger}?mode=ro", uri=True, timeout=10)

        done, done_bytes = ledger.execute(
            "SELECT COUNT(*), COALESCE(SUM(size), 0) FROM transferred WHERE status = 'done'").fetchone()
        failed = ledger.execute(
            "SELECT COUNT(*) FROM transferred WHERE status = 'failed'").fetchone()[0]

        cutoff = datetime.now(timezone.utc).timestamp() - args.window * 60
        recent_files, recent_bytes, earliest = ledger.execute(
            "SELECT COUNT(*), COALESCE(SUM(size), 0), MIN(updated_at) FROM transferred"
            " WHERE status = 'done' AND updated_at > ?",
            (datetime.fromtimestamp(cutoff, timezone.utc).isoformat(timespec="seconds"),)).fetchone()

        latest = ledger.execute(
            "SELECT path, updated_at FROM transferred WHERE status = 'done'"
            " ORDER BY updated_at DESC LIMIT 1").fetchone()
        ledger.close()

        alive, where = running()

        if args.watch:
            print("\033[2J\033[H", end="")
        print(f"  transfer   {'running' if alive else 'STOPPED'} ({where})")

        if expected_files:
            share = 100.0 * done / expected_files
            print(f"  staged     {done:,} of {expected_files:,} files   {share:.1f}%")
            print(f"  bytes      {human(done_bytes)} of {human(expected_bytes)}")
        else:
            print(f"  staged     {done:,} files, {human(done_bytes)}")

        span = args.window * 60
        if earliest:
            began = datetime.fromisoformat(earliest).timestamp()
            span = max(datetime.now(timezone.utc).timestamp() - began, 1)

        if recent_files:
            per_minute = recent_files / (span / 60)
            per_second = recent_bytes / span
            print(f"  rate       {per_minute:,.0f} files/min, {human(per_second)}/s"
                  f"   (last {args.window}m)")
            if expected_bytes and per_second > 0:
                print(f"  eta        {duration((expected_bytes - done_bytes) / per_second)}")
        else:
            print(f"  rate       nothing in the last {args.window}m"
                  f"{' — walking a folder, or stalled' if alive else ''}")

        print(f"  failures   {failed:,}")
        if latest:
            age = datetime.now(timezone.utc).timestamp() - datetime.fromisoformat(latest[1]).timestamp()
            print(f"  last file  {latest[0][:78]}")
            print(f"             {duration(age)} ago")

        if not args.watch:
            return 0
        print(f"\n  refreshing every {args.interval}s, ctrl-c to stop")
        time.sleep(args.interval)


if __name__ == "__main__":
    sys.exit(main())

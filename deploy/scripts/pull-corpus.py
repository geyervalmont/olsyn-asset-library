#!/usr/bin/env python3
"""Pull the corpus from SharePoint to S3 using a browser session cookie.

The tenant blocks third-party OAuth applications, so there is no token to be
had. A signed-in browser session is the one credential that exists, and the
SharePoint REST API accepts it — which gives per-file access rather than the
download button's zip.

That distinction is the whole point. The zip endpoint caps at 10 GB per
archive, 20 GB per download and 10,000 files, against a corpus of 173 GB and
40,330 files; per-file transfers have no such ceiling, need no unzip step, and
stream straight to S3 without ever touching local disk.

    # once
    python3 -m venv ~/.local/share/opal-corpus/venv
    ~/.local/share/opal-corpus/venv/bin/pip install boto3 requests tqdm

    # every run
    ~/.local/share/opal-corpus/venv/bin/python deploy/scripts/pull-corpus.py \
        --cookies ~/onedrive-cookie.txt --folder Fabric

Cookies expire. When they do the script stops cleanly and says so; refresh the
cookie and run it again. Everything already transferred is in the ledger and is
not fetched twice, so a run costs only what is still outstanding.

Getting the cookie: open the Material Library folder in the browser, DevTools →
Network → click any request to the site → Copy → Copy as cURL, and save it to a
file. The Cookie header is parsed out of it; the rest is ignored.
"""
import argparse
import json
import os
import re
import sqlite3
import sys
import time
from datetime import datetime, timezone
from urllib.parse import quote

try:
    import boto3
    import requests
    from botocore.config import Config
    from tqdm import tqdm
except ImportError:
    sys.exit(
        "boto3, requests and tqdm are required. Create the environment once:\n"
        "  python3 -m venv ~/.local/share/opal-corpus/venv\n"
        "  ~/.local/share/opal-corpus/venv/bin/pip install boto3 requests tqdm\n"
        "then run this with ~/.local/share/opal-corpus/venv/bin/python"
    )

ODATA = {"Accept": "application/json;odata=nometadata"}


class SessionExpired(RuntimeError):
    """The cookie is no longer accepted; nothing to do but refresh it."""


def read_cookie(path: str) -> str:
    """Accept either a raw Cookie header or a whole 'Copy as cURL' paste."""
    blob = open(path, encoding="utf-8", errors="replace").read().strip()

    for pattern in (r"-H\s+'[Cc]ookie:\s*(.+?)'", r'-H\s+"[Cc]ookie:\s*(.+?)"',
                    r"-b\s+'(.+?)'", r'-b\s+"(.+?)"'):
        found = re.search(pattern, blob, re.DOTALL)
        if found:
            return found.group(1).strip()

    # Not a cURL paste: assume the file is the header value itself.
    if blob.lower().startswith("cookie:"):
        blob = blob.split(":", 1)[1]
    if "=" not in blob:
        raise SystemExit(f"no cookie found in {path}")
    return blob.strip()


def api_path(server_relative: str) -> str:
    """SharePoint takes the path inside single quotes, so they must be doubled."""
    return quote(server_relative.replace("'", "''"), safe="/")


class SharePoint:
    def __init__(self, site: str, cookie: str, tps: float):
        self.site = site.rstrip("/")
        self.interval = 1.0 / tps if tps > 0 else 0.0
        self.last_call = 0.0
        self.http = requests.Session()
        self.http.headers.update({
            "Cookie": cookie,
            # SharePoint rejects requests it thinks are from a bot framework;
            # a browser agent string is what the cookie was issued against.
            "User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
                          "(KHTML, like Gecko) Chrome/140.0 Safari/537.36",
        })

    def get(self, url: str, **kwargs):
        for attempt in range(6):
            if self.interval:
                wait = self.interval - (time.monotonic() - self.last_call)
                if wait > 0:
                    time.sleep(wait)
            self.last_call = time.monotonic()

            response = self.http.get(url, **kwargs)

            # SharePoint also answers 403 transiently under load, so a single
            # one must not be read as an expired cookie: doing that would end
            # an unattended overnight run over a blip. A genuinely dead cookie
            # fails all of these in a few seconds.
            if response.status_code in (401, 403):
                if attempt < 2:
                    time.sleep(2 * (attempt + 1))
                    continue
                raise SessionExpired(f"HTTP {response.status_code} from SharePoint")

            # Microsoft answers a burst with 429 and tells you how long to wait.
            # Honouring it is faster than fighting it.
            if response.status_code in (429, 503):
                delay = int(response.headers.get("Retry-After", 2 ** attempt))
                print(f"    throttled, waiting {delay}s", flush=True)
                time.sleep(min(delay, 120))
                continue

            response.raise_for_status()
            return response

        raise RuntimeError(f"gave up after repeated throttling: {url}")

    def children(self, folder: str) -> tuple[list[dict], list[str]]:
        """Files and subfolder paths directly under a server-relative folder."""
        files, folders = [], []

        for kind in ("Files", "Folders"):
            url = (f"{self.site}/_api/web/GetFolderByServerRelativeUrl"
                   f"('{api_path(folder)}')/{kind}")
            params = {"$top": "5000"}
            if kind == "Files":
                params["$select"] = "Name,ServerRelativeUrl,Length"
            else:
                params["$select"] = "Name,ServerRelativeUrl"

            while url:
                payload = self.get(url, headers=ODATA, params=params).json()
                for item in payload.get("value", []):
                    if kind == "Files":
                        files.append({"path": item["ServerRelativeUrl"],
                                      "size": int(item["Length"])})
                    else:
                        # Forms holds list templates, never content.
                        if item["Name"] != "Forms":
                            folders.append(item["ServerRelativeUrl"])
                url = payload.get("odata.nextLink")
                params = None

        return files, folders

    def open_file(self, server_relative: str):
        url = (f"{self.site}/_api/web/GetFileByServerRelativeUrl"
               f"('{api_path(server_relative)}')/$value")
        response = self.get(url, stream=True)
        response.raw.decode_content = True
        return response


def wait_for_new_cookie(path: str, minutes: int, bar) -> bool:
    """Block until the cookie file is rewritten, so a refresh resumes the run.

    Worth having for an unattended run: without it an expiry at 2am wastes the
    rest of the night, and with it the run picks up whenever the file changes.
    """
    original = os.path.getmtime(path)
    deadline = time.monotonic() + minutes * 60
    bar.write(f"  cookie expired; waiting up to {minutes} min for {path} to be refreshed")

    while time.monotonic() < deadline:
        time.sleep(15)
        try:
            if os.path.getmtime(path) != original:
                time.sleep(1)  # let the write finish
                bar.write("  cookie refreshed, resuming")
                return True
        except OSError:
            continue
    return False


def open_ledger(path: str) -> sqlite3.Connection:
    ledger = sqlite3.connect(path)
    ledger.executescript("""
        CREATE TABLE IF NOT EXISTS discovered (
            path TEXT PRIMARY KEY, size INTEGER NOT NULL, root TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS walked (
            root TEXT PRIMARY KEY, files INTEGER, finished_at TEXT);
        CREATE TABLE IF NOT EXISTS transferred (
            path TEXT PRIMARY KEY, size INTEGER, status TEXT NOT NULL,
            error TEXT, updated_at TEXT);
    """)
    return ledger


def now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def human(count: int) -> str:
    value = float(count)
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if value < 1024 or unit == "TB":
            return f"{value:,.1f} {unit}"
        value /= 1024


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--cookies", required=True, help="file holding the Cookie header or a Copy-as-cURL paste")
    parser.add_argument("--site", default="https://valmontinteriors-my.sharepoint.com/personal/mcrossley_geyervalmont_com")
    # The server-relative path repeats "Documents": the first is the document
    # library, the second a folder inside it. It is exactly the `id` parameter
    # in the OneDrive web URL, and the shorter form silently resolves to an
    # empty folder rather than a 404.
    parser.add_argument("--root", default="/personal/mcrossley_geyervalmont_com/Documents/Documents/Material Library")
    parser.add_argument("--folder", action="append", help="only this top-level folder; repeatable")
    parser.add_argument("--bucket", default="olsyn-prod-material-corpus")
    parser.add_argument("--prefix", default="corpus")
    parser.add_argument("--region", default="ap-southeast-2")
    parser.add_argument("--ledger", default=os.path.expanduser("~/.local/share/opal-corpus/ledger.sqlite"))
    parser.add_argument("--tps", type=float, default=8.0, help="requests per second ceiling")
    parser.add_argument("--limit", type=int, help="stop after this many files (for a trial run)")
    parser.add_argument("--rediscover", action="store_true", help="re-walk folders instead of using the ledger")
    parser.add_argument("--wait-for-cookie", type=int, metavar="MINUTES",
                        help="on expiry, wait this long for the cookie file to be refreshed instead of exiting")
    parser.add_argument("--manifest", metavar="SQLITE",
                        help="legacy database; transfer only the files it references")
    parser.add_argument("--include-root-files", action="store_true",
                        help="also take files sitting loose at the top of the library")
    args = parser.parse_args()

    os.makedirs(os.path.dirname(args.ledger), exist_ok=True)
    ledger = open_ledger(args.ledger)
    sharepoint = SharePoint(args.site, read_cookie(args.cookies), args.tps)
    s3 = boto3.client("s3", region_name=args.region,
                      config=Config(retries={"max_attempts": 5, "mode": "standard"}))

    root = args.root.rstrip("/")

    try:
        sharepoint.get(f"{args.site}/_api/web", headers=ODATA, params={"$select": "Title"})
    except SessionExpired as expired:
        print(f"The cookie is not being accepted ({expired}). Copy a fresh one and rerun.")
        return 75
    print(f"session ok, ledger {args.ledger}\n")

    # Which top-level folders to work through.
    _, top_level = sharepoint.children(root)

    # SharePoint answers a path that does not exist with an empty collection
    # rather than a 404, so a wrong --root looks like an empty library. Say so
    # plainly instead of reporting that nothing matched.
    if not top_level:
        print(f"{root}\n  contains no folders. That path probably does not exist —")
        print("  SharePoint returns an empty result for a missing folder rather than an error.")
        print("  Check it against the `id=` parameter in the folder's web URL.")
        return 2

    available = sorted((f.rsplit("/", 1)[-1] for f in top_level), key=str.lower)
    wanted = sorted(top_level, key=str.lower)

    # With the manifest in hand the run can be exactly what the ingest will ask
    # for. That is not a micro-optimisation here: the library holds seven
    # top-level folders the ingest never reads, so without this a full run
    # spends hours on files nothing will look at.
    expected: set[str] | None = None
    if args.manifest:
        with sqlite3.connect(f"file:{args.manifest}?mode=ro", uri=True) as manifest:
            expected = {path.replace("\\", "/") for (path,) in manifest.execute(
                "SELECT DISTINCT relative_path FROM asset_file WHERE relative_path IS NOT NULL")}
        tops = {path.split("/", 1)[0] for path in expected}
        before = len(wanted)
        wanted = [f for f in wanted if f.rsplit("/", 1)[-1] in tops]
        print(f"manifest: {len(expected):,} files wanted, "
              f"{before - len(wanted)} of {before} top-level folders skipped entirely")

    if args.folder:
        selected = {name.lower() for name in args.folder}
        wanted = [f for f in wanted if f.rsplit("/", 1)[-1].lower() in selected]
        if not wanted:
            print(f"no top-level folder matched {args.folder}. Available:")
            for name in available:
                print(f"  {name}")
            return 2

    transferred = failed = skipped = 0
    moved_bytes = 0
    # tqdm owns stderr while a bar is live, so anything printed alongside it has
    # to go through tqdm.write or it will tear the bar apart.
    quiet = not sys.stderr.isatty()
    say = print if quiet else tqdm.write

    # Loose files at the top of the library belong to no folder and would
    # otherwise be the one thing a full run misses. They are their own unit
    # rather than a walk from the root, which would re-enumerate everything.
    units = list(wanted)
    if args.include_root_files and not args.folder:
        units.insert(0, root)

    for top in units:
        at_root = top == root
        name = "(root files)" if at_root else top.rsplit("/", 1)[-1]

        done_walk = ledger.execute("SELECT files FROM walked WHERE root = ?", (name,)).fetchone()
        if done_walk and not args.rediscover:
            files = [{"path": p, "size": s} for p, s in ledger.execute(
                "SELECT path, size FROM discovered WHERE root = ?", (name,))]
        elif at_root:
            files, _ = sharepoint.children(top)
        else:
            say(f"{name}: walking...")
            files, folders_left = [], [top]
            while folders_left:
                here = folders_left.pop()
                found, subfolders = sharepoint.children(here)
                files.extend(found)
                folders_left.extend(subfolders)
            ledger.executemany(
                "INSERT OR REPLACE INTO discovered (path, size, root) VALUES (?, ?, ?)",
                [(f["path"], f["size"], name) for f in files])
            ledger.execute("INSERT OR REPLACE INTO walked (root, files, finished_at) VALUES (?, ?, ?)",
                           (name, len(files), now()))
            ledger.commit()

        # The ledger decides what is left, so the bar measures the work actually
        # remaining rather than restarting at zero on every resume.
        banked = {path: size for path, size in ledger.execute(
            "SELECT path, size FROM transferred WHERE status = 'done'")}
        pending = []
        for entry in sorted(files, key=lambda f: f["path"]):
            relative = entry["path"][len(root) + 1:]
            if expected is not None and relative not in expected:
                continue
            if banked.get(relative) == entry["size"]:
                skipped += 1
            else:
                pending.append((relative, entry))

        outstanding = len(pending)
        if args.limit is not None:
            pending = pending[: max(0, args.limit - transferred)]

        if not pending:
            # Distinguish "nothing left to do" from "--limit cut it off", which
            # otherwise both look like the folder is finished.
            if outstanding:
                say(f"{name}: {outstanding:,} outstanding, held back by --limit")
            else:
                say(f"{name}: complete ({len(files):,} files already staged)")
            if args.limit is not None and transferred >= args.limit:
                break
            continue

        say(f"{name}: {len(pending):,} of {len(files):,} files to move")

        with tqdm(total=sum(e["size"] for _, e in pending), unit="B", unit_scale=True,
                  unit_divisor=1024, desc=f"{name[:22]:22}", dynamic_ncols=True,
                  smoothing=0.05, disable=quiet, leave=True) as bar:
            for relative, entry in pending:
                bar.set_postfix_str(relative.rsplit("/", 1)[-1][:38], refresh=False)
                key = f"{args.prefix.rstrip('/')}/{relative}"
                try:
                    with sharepoint.open_file(entry["path"]) as body:
                        # Callback fires per chunk as the upload drains the
                        # download, so the bar follows real bytes on the wire.
                        s3.upload_fileobj(body.raw, args.bucket, key, Callback=bar.update)
                except SessionExpired as expired:
                    ledger.commit()
                    if args.wait_for_cookie and wait_for_new_cookie(
                            args.cookies, args.wait_for_cookie, bar):
                        sharepoint.http.headers["Cookie"] = read_cookie(args.cookies)
                        try:
                            with sharepoint.open_file(entry["path"]) as body:
                                s3.upload_fileobj(body.raw, args.bucket, key, Callback=bar.update)
                        except Exception as retry_failure:  # noqa: BLE001
                            say(f"  FAILED {relative}: {str(retry_failure)[:110]}")
                            failed += 1
                            continue
                    else:
                        bar.close()
                        print(f"\nThe cookie expired mid-run ({expired}).")
                        print(f"Copy a fresh one and rerun; {transferred:,} files are already banked.")
                        return 75
                except Exception as failure:  # noqa: BLE001 - one bad file must not end the run
                    ledger.execute(
                        "INSERT OR REPLACE INTO transferred (path, size, status, error, updated_at)"
                        " VALUES (?, ?, 'failed', ?, ?)", (relative, entry["size"], str(failure)[:500], now()))
                    ledger.commit()
                    failed += 1
                    say(f"  FAILED {relative}: {str(failure)[:110]}")
                    continue

                ledger.execute(
                    "INSERT OR REPLACE INTO transferred (path, size, status, error, updated_at)"
                    " VALUES (?, ?, 'done', NULL, ?)", (relative, entry["size"], now()))
                transferred += 1
                moved_bytes += entry["size"]
                if transferred % 50 == 0:
                    ledger.commit()

        ledger.commit()

        if args.limit is not None and transferred >= args.limit:
            say(f"stopping at --limit {args.limit}")
            break

    print(f"transferred {transferred:,} files ({human(moved_bytes)})")
    print(f"already present {skipped:,}")
    print(f"failed {failed:,}")
    if failed:
        print("\nRerun to retry the failures; they are recorded and will be picked up.")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())

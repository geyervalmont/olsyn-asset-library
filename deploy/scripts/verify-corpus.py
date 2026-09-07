#!/usr/bin/env python3
"""Check the staged corpus against the legacy database's manifest.

The database knows exactly which 40,330 files the ingest will ask for and how
big each one is, so "did the upload finish correctly" is a question with a real
answer rather than a guess. Run it after staging and before the ingest.

    deploy/scripts/verify-corpus.py \
        --database /path/to/material_assets.sqlite \
        --bucket olsyn-prod-material-corpus --prefix corpus

Reports four things, in the order they matter:

  missing       the ingest will skip these outright
  size mismatch truncated or half-written uploads
  case only     present, but under a different case

The last one is the trap. The corpus was authored on Windows, which does not
distinguish Foo.png from foo.png; S3 does. A file whose case drifted at any
point copies fine, looks fine in a listing, and then misses every lookup.
"""
import argparse
import sqlite3
import subprocess
import sys


def staged_objects(bucket: str, prefix: str, region: str) -> dict[str, int]:
    """Every object under the prefix, as {relative path: size}."""
    result = subprocess.run(
        ["aws", "s3", "ls", f"s3://{bucket}/{prefix.rstrip('/')}/",
         "--recursive", "--region", region],
        capture_output=True, text=True,
    )

    # An empty prefix is a non-zero exit with no output, which is a legitimate
    # answer here — nothing staged yet — and must not look like a failure.
    if result.returncode != 0:
        if result.stderr.strip():
            raise SystemExit(f"listing s3://{bucket}/{prefix} failed: {result.stderr.strip()}")
        return {}

    listing = result.stdout

    objects = {}
    head = f"{prefix.rstrip('/')}/"
    for line in listing.splitlines():
        # date time size key, and the key may itself contain spaces
        parts = line.split(maxsplit=3)
        if len(parts) < 4:
            continue
        size, key = parts[2], parts[3]
        if key.startswith(head):
            objects[key[len(head):]] = int(size)
    return objects


def expected_files(database: str) -> dict[str, int]:
    connection = sqlite3.connect(f"file:{database}?mode=ro", uri=True)
    rows = connection.execute(
        "SELECT DISTINCT relative_path, bytes FROM asset_file "
        "WHERE relative_path IS NOT NULL"
    )
    return {path.replace("\\", "/"): size for path, size in rows}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--database", required=True)
    parser.add_argument("--bucket", default="olsyn-prod-material-corpus")
    parser.add_argument("--prefix", default="corpus")
    parser.add_argument("--region", default="ap-southeast-2")
    parser.add_argument("--show", type=int, default=15, help="examples per category")
    args = parser.parse_args()

    expected = expected_files(args.database)
    staged = staged_objects(args.bucket, args.prefix, args.region)
    folded = {path.lower(): path for path in staged}

    missing, mismatched, case_only = [], [], []
    for path, size in sorted(expected.items()):
        if path in staged:
            if staged[path] != size:
                mismatched.append((path, size, staged[path]))
        elif path.lower() in folded:
            case_only.append((path, folded[path.lower()]))
        else:
            missing.append(path)

    print(f"expected   {len(expected):>7,} files from the manifest")
    print(f"staged     {len(staged):>7,} objects under {args.prefix}/")
    print(f"missing    {len(missing):>7,}")
    print(f"size differ{len(mismatched):>7,}")
    print(f"case only  {len(case_only):>7,}")

    for title, rows in (
        ("MISSING", [f"  {p}" for p in missing]),
        ("SIZE MISMATCH", [f"  {p}  manifest {a:,} vs staged {b:,}" for p, a, b in mismatched]),
        ("CASE ONLY", [f"  manifest {p}\n    staged {s}" for p, s in case_only]),
    ):
        if rows:
            print(f"\n{title}")
            print("\n".join(rows[: args.show]))
            if len(rows) > args.show:
                print(f"  ... and {len(rows) - args.show:,} more")

    # Extra objects are fine: previews and compressed variants live alongside
    # the files the database names, and the ingest simply ignores them.
    ok = not missing and not mismatched and not case_only
    print("\nComplete." if ok else "\nNot ready to ingest.")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())

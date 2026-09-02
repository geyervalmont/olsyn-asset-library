#!/usr/bin/env bash
set -euo pipefail

prismfs_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose="$prismfs_root/dev/compose.sh"
s3_port="$($compose port rustfs 9000 | sed 's/.*://')"
smb_port="$($compose port prismfs-smb 445 | sed 's/.*://')"

printf 'S3:  http://127.0.0.1:%s\n' "$s3_port"
printf 'SMB: smb://127.0.0.1:%s/prismfs\n' "$smb_port"

#!/usr/bin/env bash
# Lists the share through SMB from inside the container and reads one file
# back, comparing its SHA-256 with the FUSE mount's copy.
set -euo pipefail

prismfs_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
drive="$prismfs_root/dev/drive.sh"
share="$(sed -n 's/^PRISMFS_SMB_SHARE=//p' "${PRISMFS_DRIVE_ENV:-$prismfs_root/drive.env}" | tail -1)"
share="${share:-opal}"

printf 'drive: share //127.0.0.1/%s\n' "$share"
"$drive" exec -T prismfs-drive smbclient "//127.0.0.1/$share" -N -c 'recurse; ls' | grep -v '^$' | head -40

first="$("$drive" exec -T prismfs-drive sh -c 'find /srv/prismfs -type f | head -1')"
if [ -z "$first" ]; then
    printf 'drive: the drive has no published files yet\n'
    exit 0
fi
relative="${first#/srv/prismfs/}"
printf 'drive: reading %s over SMB\n' "$relative"
"$drive" exec -T prismfs-drive sh -ec "
    smbclient //127.0.0.1/$share -N -c 'get \"$relative\" /tmp/drive-check' >/dev/null
    fuse=\$(sha256sum < '$first' | cut -d' ' -f1)
    smb=\$(sha256sum < /tmp/drive-check | cut -d' ' -f1)
    printf '  fuse %s\n  smb  %s\n' \"\$fuse\" \"\$smb\"
    test \"\$fuse\" = \"\$smb\"
"
printf 'drive: ok\n'

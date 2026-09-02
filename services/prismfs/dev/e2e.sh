#!/usr/bin/env bash
set -euo pipefail

prismfs_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose="$prismfs_root/dev/compose.sh"
expected='Hello from PrismFS over S3, FUSE, and SMB!'
download="$(mktemp)"
client_config="$(mktemp)"
printf '[global]\nclient min protocol = SMB2_10\n' > "$client_config"

cleanup() {
    rm -f "$download" "$client_config"
    "$compose" down --volumes --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

printf 'e2e: building and starting RustFS, PrismFS FUSE, and Samba\n'
"$compose" up --build --detach --wait

s3_port="$($compose port rustfs 9000 | sed 's/.*://')"
smb_port="$($compose port prismfs-smb 445 | sed 's/.*://')"
curl --fail --silent --show-error "http://127.0.0.1:$s3_port/health/ready" >/dev/null

printf 'e2e: verifying the FUSE projection\n'
fuse_value="$($compose exec -T prismfs-smb cat /srv/prismfs/public/hello.txt)"
test "$fuse_value" = "$expected"
"$compose" exec -T prismfs-smb test ! -e /srv/prismfs/private
if "$compose" exec -T prismfs-smb sh -c 'printf forbidden > /srv/prismfs/rejected.txt' 2>/dev/null; then
    printf 'e2e: FUSE unexpectedly permitted a write\n' >&2
    exit 1
fi

printf 'e2e: verifying the SMB projection\n'
smb_listing="$(smbclient -s "$client_config" "//127.0.0.1/prismfs" -p "$smb_port" -N -c 'ls')"
printf '%s\n' "$smb_listing" | grep -q 'public'
if printf '%s\n' "$smb_listing" | grep -q 'private'; then
    printf 'e2e: denied directory leaked through SMB\n' >&2
    exit 1
fi
smbclient -s "$client_config" "//127.0.0.1/prismfs" -p "$smb_port" -N \
    -c "get public/hello.txt $download" >/dev/null
test "$(cat "$download")" = "$expected"
if smbclient -s "$client_config" "//127.0.0.1/prismfs" -p "$smb_port" -N \
    -c "put $download rejected.txt" >/dev/null 2>&1; then
    printf 'e2e: SMB unexpectedly permitted a write\n' >&2
    exit 1
fi

printf 'e2e: S3 -> policy/cache -> FUSE -> POSIX and SMB: ok\n'

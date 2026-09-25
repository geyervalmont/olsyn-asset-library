# Nucleus material-library bridge

This deployment exposes the existing governed OPAL studio-share PrismFS namespace as a private, read-only S3 service. Nucleus mounts bucket `materials` at `/Libraries/Materials`. Data stays in OPAL's S3 storage; there is no bulk copy into Nucleus or dependency on the R760.

## Boundaries

- Shared published library view, governed by the studio-share Drive. This is not a personal OPAL session and does not impersonate individual Nucleus users.
- Existing drive currently projects published texture derivatives under immutable `by-id` paths. Canonical USDZ packages and the personal drive's `by-name` view are not present in that shared manifest.
- Changes refresh through PrismFS every 30 seconds, then the facade directory cache (10 seconds) and client caches. This is not immediate revocation: use only shared library content.
- Both the FUSE sidecar mount and S3 server are read-only. Publish/edit in OPAL. Project scene files remain writable under normal Nucleus project ACLs.
- Depends on the cloud OPAL node, OPAL manifest API, object storage and Nucleus. One bridge replica; restarting it briefly interrupts library reads. Existing material SMB service is independent.
- No public S3 endpoint. NetworkPolicy admits Nucleus/OPAL S3 access and monitoring metrics. Credentials are service credentials stored in Kubernetes and Nucleus's native mount configuration, never in client apps or this repository.

## Resources

Apply `resources.json`, `network-policy.json`, and `monitoring.json` in namespace opal. Requires existing `prismfs-studio-share` ConfigMap/Secret and `ghcr-secret`. The additional Secret `nucleus-materials-s3` contains `access-key`, `secret-key`, and `auth-key`. The latter must be the **CSV-quoted** pair (JSON encoding of the string `ACCESS,SECRET`), since rclone parses the environment variable as a string array. Do not rotate the existing PrismFS drive token.

Readiness authenticates to the local S3 facade, downloads the existing 1,152-byte canary and verifies its SHA-256 every 30 seconds. Prometheus scrapes PrismFS reads, latency, manifest freshness, cache and audit counters. Existing PrismFS stale-manifest/read-error rules cover this pod; separate bridge availability/telemetry alerts are included. Alert routing and a dedicated Nucleus Manager dashboard card are not configured here.

Disable by unmounting the Nucleus mount first, then deleting these Kubernetes resources and its dedicated Secret. Do not delete the existing studio-share resources or source S3 objects.

## Native mount and validation

Run mount.py using the Nucleus Drive Python environment (desktop/.venv, desktop directory on PYTHONPATH). It reads JSON from stdin with password (Nucleus omniverse administrator), access and secret (dedicated facade credential), never prints them, and refuses to modify an existing /Libraries folder. Inspect existing namespaces manually before adapting it. Do not put secrets in command-line arguments, shell history or committed files. Native mount options are retained by Nucleus; protect its metadata backups.

The native S3 resolver supplies its own read ACL for users, gm and omniverse. **Parent folder restrictions do not protect mounted content.** This integration is appropriate only for the shared library scope. Tenant-private material requires a different authorization boundary. The existing studio-share drive has no explicit drive grants and no tenant scope; adding private grants to it would also expose those assets through this mount.

validate-user.py reads password, a known canary key and its sha256 from stdin, creates a temporary ordinary user, verifies listing/read/write denial and disables that account in a finally block. It does not change folder ACLs. Disabled validation users remain for audit.

Use stable by-id paths for cross-project dependencies. The local mounted path on this Mac is ~/Olsyn Nucleus/Libraries/Materials. Native clients use omniverse://nucleus.olsyn.com/Libraries/Materials/. References relative to the Nucleus root remain available to all machines mounting that same namespace. Publishing a new version creates a new immutable path; existing scene references stay pinned.

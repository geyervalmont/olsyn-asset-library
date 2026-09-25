# Nucleus material-library bridge

This deployment exposes the existing governed OPAL studio-share PrismFS namespace as a private, read-only S3 service. Nucleus mounts bucket `materials` at `/Libraries/Materials`. Data stays in OPAL's S3 storage; there is no bulk copy into Nucleus or dependency on the R760.

## Boundaries

- Shared published library view, governed by the studio-share Drive. This is not a personal OPAL session and does not impersonate individual Nucleus users.
- Stable shared drives project published derivatives and canonical USDZ packages under immutable `by-id` paths, plus current-publication aliases under `by-name`. Both views reference the same storage objects. Existing texture paths are retained. Canonical keys come from Package metadata and the configured package storage disk.
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

## Complete namespace projection

`DriveNamespace::projectionEntries()` builds the complete stable tree independently of JSON/YAML serialization. `entries()` and variant consumer resolvers keep their derivative-only contract. Personal and shared transport use the same construction with `visibleTo(user)` and `visibleToDrive(drive)` respectively. Shared projection never impersonates a publisher.

Canonical immutable path: `/materials/by-id/{materialUuid}/{variantUuid}/v{version}/canonical/{sha256}.usdz`. The readable alias is `/materials/by-name/{category}/{material}/{variant}/canonical/material.usdz`. Derivative aliases end in `/{target}/{quality}/{role}.{extension}`. Aliases follow the current published version and latest verified converter generation; immutable paths retain every accessible publication/cache generation. Names use the existing Windows-safe sanitizer and collision suffixes. Roots are configurable.

Target-restricted drives include canonical packages only for target `omniverse`; other target restrictions (including Revit) exclude the full-resolution canonical object. Legacy non-stable layouts retain their existing derivative-only named tree. YAML still consists only of version and path/object records; ordering is deterministic and ETag is over the exact serialized bytes.

No bridge reconfiguration, remount, source upload or token rotation is needed. Allow a manifest poll, the facade directory cache, and any native client caches to refresh. `by-name` is for browsing; production scenes should pin `by-id`.

The ingestion page and personal `/upload` feature do not add writes or private batches to this shared projection. See `docs/drive-upload-queue.md`.

Rollback: redeploy `ghcr.io/geyervalmont/opal:sha-f5a8e68` (web, horizon, scheduler, reverb) to return to the prior derivative-only manifest. Keep the additive intake migration/data and existing bridge configuration. New canonical/by-name paths disappear on rollback; the original stable texture paths survive. Do not publish new scene references until native read validation completes.

## Refresh cost and cache invalidation

The shared manifest is cached after bearer-token authorization. PostgreSQL statement triggers advance a transactional namespace revision for material/version/package/derivative/file/grant/drive and naming metadata, including raw imports and pivot writes. The cache also fingerprints the drive configuration and storage bucket mapping. Renames, publication and revocation therefore invalidate cached YAML without waiting for its 30-minute retention TTL. Each drive retains one cached document; synchronized SMB/Nucleus refreshes share a build lock. A generation that overlaps a metadata change is not cached. Non-PostgreSQL environments use uncached generation.

The revision row briefly serializes library metadata writes at commit; keep bulk ingestion's database transactions short and avoid running converters or remote transfers after their first catalog mutation. Raw intake upload rows do not affect the published namespace or its revision. Shared drive tokens are checked live, including on conditional requests. This cache does not cache personal authorization or extend client manifest freshness. Increment `SharedDriveManifest::CACHE_VERSION` when changing projection semantics without changing stored metadata.

The completed [namespace validation receipt](validation-2026-09-25-namespace.json) records 29,336 paths (2,377 canonical packages), preserved original paths/objects, matching native by-id/by-name checksums, and successful OpenUSD dependency checks on both a small package and a 153 MB textured package. After the cache rollout, a conditional production request took 250 ms with the same ETag; the uncached comparison had taken 36.22 seconds. Nucleus and SMB were healthy, and the Windows 0.1.4 installer passed its actual mount/install tests and was discovered by the website.

## Unified OPAL root

`omniverse://nucleus.olsyn.com/OPAL/materials/` is now the preferred shared mount.
`/Libraries/Materials/` remains an alias backed by the **same** S3 facade, shared
manifest and source objects. No package is copied or rewritten. `opal-root.py`
adds the new native parent, mount and restricted native `/OPAL/ingestion/upload` and `/OPAL/ingestion/workspace` directories;
it leaves existing paths and ACLs unchanged when run again.

Personal uploads use the [Nucleus drive adapter](../nucleus-drive/README.md),
not the shared material S3 resolver. Future published namespace changes apply to
both mount names and the personal Windows projection through `DriveNamespace`.

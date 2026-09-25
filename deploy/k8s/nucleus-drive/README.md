# Unified OPAL drive: Nucleus intake adapter

OPAL owns the logical layout (`DriveLayout`), published namespace (`DriveNamespace`)
and intake inventory (`IntakeNamespace`). The website, Windows and this adapter
use the same intake sessions, checksums, quotas and permission checks. No material
parsing, publishing or AI ingestion is started automatically.

- Windows: `O:\materials` and `O:\upload`; confirmed files from other clients
  appear through the authenticated drive manifest, normally within 30 seconds.
- Nucleus: `/OPAL/materials` uses the shared read-only library. The previous
  `/Libraries/Materials` path remains available for existing scenes.
- Nucleus intake: `/OPAL/upload/<Nucleus username>/<batch UUID>`. Only linked
  contributors see upload access; each batch uses the same UUID as their Windows
  inbox and website ingestion queue. Nucleus has one global namespace, so user
  and batch folders are explicit. Native files are copied through the personal
  HTTPS API; this does not make the shared S3 mount writable.
- Website submission rotates the inbox. The old native batch becomes inaccessible
  to its contributor and is retained for administrator recovery, never replayed
  into a new batch or workspace. Review/download history stays on the website.
- Uploads are append-only. Different bytes at an existing path cause a conflict;
  neither copy is replaced. Use a new name or batch. Native copies are retained,
  and deleted confirmed native files are restored from OPAL on the next pass.
  Folder renames/deletes are not synchronized editing operations.

## Enrollment and deployment

The operator must confirm the Nucleus username belongs to the OPAL user. Never
infer this from a similar display name or bind the shared Nucleus administrator.

1. Run `python3 integrations/nucleus-drive/link.py --username USER --output /private/link.json`.
   Have the person approve the printed OPAL browser link. Only drive scopes are
   issued. The token is written with mode 0600 and is never printed.
2. Merge the enrollment into a private `links.json` object containing `host`
   (`nucleus.olsyn.com`), `origin` (`https://opal.olsyn.com`), and `links` (array of
   enrollment objects). Validate the username exists on Nucleus. Create/update
   Secret `opal-nucleus-drive-links`, key `links.json`, in `olsyn-nucleus-control`.
   Preserve other enrolled users. Never commit this file or print its contents.
3. Run `bash deploy/k8s/nucleus-drive/apply.sh`, or dispatch the **Nucleus drive**
   GitHub workflow. It tests the adapter before deployment. The base SDK image is
   pinned; adapter code is a ConfigMap, with persistent transfer receipts on a PVC.
4. Drop a small nested test file into the current batch, confirm its checksum in
   the website queue, and verify it appears in Windows. Submit on the website and
   confirm a new batch appears. The adapter waits for two stable observations,
   then rechecks the source version after copying. Polling is every 10 seconds;
   transfer time and client caches add latency.

Configuration changes require an adapter restart. Empty enrollment is supported:
`upload` remains restricted until an account has been explicitly linked. On
restart, grants from removed enrollments are removed from owned user/batch roots.
The adapter owns only `/OPAL/upload` ACLs; never store unrelated native content
there. Link removal revokes native inbox access on restart; also revoke the OPAL
connection/token from Apps & drive. Submitted files remain governed by website
reviewer permissions.

## Status and recovery

Existing drive heartbeats report **Nucleus**, its current batch path, connection
state and conflicts to Apps & drive. `/state/health.json`, container logs, readiness
and liveness expose adapter health without filenames or credentials. Failed
transfers retry against the same server reservation; receipts persist across
restarts. Never delete native inputs or PVCs to clear an error. A conflict means
rename/rebatch through the normal workflow, not overwrite server bytes.

A single adapter replica performs reconciliation; rollout uses Recreate. Native
ACL revocation requires a running Nucleus/adapter and is not instantaneous. A
process failure is handled by Kubernetes restart; a cluster/network outage may
leave previously granted native access until recovery. Native files are copies,
not the Windows client's per-read authorization layer. Shared published material
visibility continues to use the shared drive policy, not individual OPAL grants.
Future namespace data changes propagate server-side; entirely new filesystem
capabilities may still need a client release.

Rollback: restore the previous adapter ConfigMap/image and restart. Restore the
previous OPAL web image if necessary; the new manifest fields are additive and
older Windows clients ignore them. Keep `/Libraries/Materials` and its token.

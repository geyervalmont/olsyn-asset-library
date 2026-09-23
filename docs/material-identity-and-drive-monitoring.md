# Material identity and drive monitoring

Materials and colourways have permanent UUIDv7 public IDs (`uuid`). Existing readable codes and integer database keys remain compatible. The migration backfills IDs, adds UUIDv7 database defaults for bulk writers/rolling deployments, and prevents changes to IDs in PostgreSQL and the models. Backfilled timestamps describe identity assignment, not original creation. Material details expose selectable IDs. API material/variant routes accept either code or UUID and apply the same visibility checks.

## Stable drive paths

New drives created in the UI use `path_layout=stable`. Existing drives retain `friendly` paths to preserve references. Do not switch a mounted drive's layout or root in place: provision a new drive/share and explicitly migrate clients.

```
/materials/by-id/{material_uuid}/{variant_uuid}/v{version}/{target}/{quality}/{derivative_uuid}/{role}.{ext}
```

Stable manifests include all published versions and converter generations while applying current material visibility. Unpublished versions are excluded. Object keys remain content addressed. Retention requires keeping the database history and backing S3 objects; UUIDs do not prevent administrative deletion or permission revocation. Target/quality/role vocabulary and the drive root must remain fixed after use.

`GET /api/v1/variants/{uuid}/paths?drive={slug}` selects the current version's newest verified converter generation per target/quality. Add `&version=1` for a published historical version (stable drives only). Responses include material/variant UUID, material version, derivative UUID, source package SHA-256 and each file SHA-256. Store the returned path and derivative UUID for exact output pinning: asking for the same material version later can select a newer converter generation. Old paths remain present.

Revit and Omniverse should share material UUID, variant UUID and material version. Target, resolution and derivative UUID distinguish their outputs. This change does not yet update Revit's stored platform metadata or implement shader/scale equivalence or an Omniverse extension.

## Monitoring deployment

PrismFS exposes Prometheus metrics when `PRISMFS_METRICS_LISTEN` is set. The Kubernetes drive template enables `0.0.0.0:9090` behind a ClusterIP service; port 9090 is not added to the SMB LoadBalancer. Keep telemetry private. Labels omit paths, object keys, principals and tokens.

After provisioning the drive and deploying images built from this change:

```
kubectl --context olsyn-edge apply -k deploy/k8s/prismfs/monitoring
```

The separate monitoring bundle requires Prometheus Operator CRDs, kube-state-metrics and a Grafana dashboard sidecar watching `grafana_dashboard=1` in `opal`. Prometheus must select this ServiceMonitor and PrometheusRule. The current cluster has those components. The dashboard is **OPAL · Material drive**, UID `opal-prismfs`. Rules assume the template instance `studio-share`; adapt the explicit instance selectors when provisioning another drive. Do not install the absence alert before provisioning its target.

Metrics cover filesystem operations/results, read duration, bytes, cache hits/misses/bytes/entries, handles, manifest freshness/file count, and audit queue/delivery/loss. Only validated manifest loads or subsequent unchanged responses advance freshness. Invalid manifests retain the previous namespace and clear the ETag so a subsequent 304 cannot mask rejection.

Alerts cover an absent/unreachable exporter, stale manifests, failed/slow reads, restarts, unavailable SMB replica, failed audit shipping, dropped audit events and memory pressure. They use the cluster's existing Alertmanager routing; no notification destination is configured here. When `PRISMFS_PROBE_PATH` and `PRISMFS_PROBE_SHA256` are configured, SMB readiness downloads that file and verifies its SHA-256 every 30 seconds (otherwise it lists the directory). Production pins a 1,152-byte published texture. This verifies the SMB/FUSE read path, including cached reads; it is not a workstation-to-share availability probe or a forced S3 fetch on every run.

## Production deployment — 23 September 2026

The application is deployed as `ghcr.io/geyervalmont/opal:sha-ec9322e`. UUIDv7 backfill completed for 334 materials, 6,494 variants and 8,504 derivatives, with no missing, invalid or duplicate UUIDs. All four app workloads and the public health endpoint passed.

`studio-share` is provisioned with stable paths and 12,291 manifest files. The private drive runs on the OPAL node using its S3 instance role and pinned PrismFS/Samba images from commit `ec9322ecc1b7170d3b122d252d0f60c97c3e6729`. The `prismfs-studio-share` Kubernetes Secret holds its generated drive token and SMB password. The production overlay excludes the example Secret, so redeploying does not overwrite credentials.

```sh
kubectl --context olsyn-edge apply -k deploy/k8s/overlays/prismfs-production
kubectl --context olsyn-edge -n opal rollout status deployment/prismfs-studio-share
kubectl --context olsyn-edge apply -k deploy/k8s/prismfs/monitoring
```

The service is ClusterIP only: `prismfs-studio-share.opal.svc.cluster.local:445`, share `opal`. It is not publicly exposed or yet reachable from ordinary designer workstations. A separate client pod downloaded the pinned file through this service and verified its SHA-256; the continuous readiness check also passed. Prometheus reported the scrape target up, the 12,291 files, successful audit delivery, and all nine alert rules healthy/inactive. Grafana loaded [OPAL · Material drive](https://grafana.olsyn.com/d/opal-prismfs).

## Remaining production work

Confirm office/VPN reachability and a permanent private DNS/UNC name, then validate a real Windows/Revit client and a canary from that network. Exercise restart and control-plane/S3 outage recovery before relying on project paths.

The existing in-memory range cache is still unbounded and nonpersistent; memory monitoring makes growth visible but does not fix it. Existing friendly-path open handles are not pinned across namespace replacement. The template is still one replica using Recreate, so deployments interrupt the share. These limitations need separate hardening before claiming production reliability or offline availability.

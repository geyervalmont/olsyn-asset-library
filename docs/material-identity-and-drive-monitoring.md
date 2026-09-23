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

Alerts cover an absent/unreachable exporter, stale manifests, failed/slow reads, restarts, unavailable SMB replica, failed audit shipping, dropped audit events and memory pressure. They use the cluster's existing Alertmanager routing; no notification destination is configured here. SMB readiness performs an authenticated directory listing. This is not a file-content or workstation-to-share availability probe.

## Remaining production work

The production share is not provisioned by these changes. Confirm office/VPN reachability and a permanent private DNS/UNC name, provision credentials and the drive token, pin container images, deploy the drive, then install monitoring. Validate a real Windows/Revit client and a remote SMB canary that downloads a known published file and checks SHA-256. Exercise restart and control-plane/S3 outage recovery before relying on project paths.

The existing in-memory range cache is still unbounded and nonpersistent; memory monitoring makes growth visible but does not fix it. Existing friendly-path open handles are not pinned across namespace replacement. The template is still one replica using Recreate, so deployments interrupt the share. These limitations need separate hardening before claiming production reliability or offline availability.

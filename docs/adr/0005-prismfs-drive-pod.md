# ADR 0005: one PrismFS pod per drive, with a Samba sidecar

- Status: accepted
- Date: 2026-09-03

## Context

The control plane defines drives: a named projection of published material
files with its own token and visibility. Clients (Revit through the OPAL
extension, designers browsing) reach a drive as an SMB share with a stable
UNC path. The production platform is Kubernetes. PrismFS already mounts a
drive from the control-plane manifest through FUSE and Samba exports that
mount (ADR 0004); what remained open was how that runs as a workload.

## Decision

Run one pod per drive. The pod holds two containers sharing an `emptyDir`:

- `prismfs` mounts the drive at `/srv/prismfs` from
  `…/prismfs/drives/{slug}/manifest.yaml`, refreshes it on an interval, and
  reads objects from the bucket. It is privileged (FUSE needs `SYS_ADMIN`
  and `/dev/fuse`; making the mount visible to another container needs
  `Bidirectional` mount propagation, which most runtimes only grant to
  privileged containers).
- `samba` waits for the mountpoint, renders the read-only guest share with
  `prismfs samba-config`, and runs `smbd` on 445. It sees the mount through
  `HostToContainer` propagation and is not privileged.

A `ConfigMap` carries the slug, URLs, bucket and intervals; a `Secret` carries
the drive token and read-only object-store credentials. The drive token
authenticates both the manifest fetch and the access-event posts. A
`Service` on TCP 445 gives the drive its address; `LoadBalancer` when each
drive needs its own IP, `ClusterIP` when clients are already on the cluster
network.

PrismFS ships `read` and `open` events to
`…/prismfs/drives/{slug}/accesses` in batches (bounded queue, retry on the
next flush), so the control plane's file-access record covers drive reads as
well as web downloads. Everything else stays in the pod's JSON log.

The same image runs on a bare host under systemd (`deploy/systemd`), with a
host `smbd` if SMB is needed there.

## Consequences

- Drives scale and fail independently; a manifest that is rejected or a
  bucket that is unreachable affects one share.
- `Recreate` deployments: a FUSE mount cannot be handed between pods, so a
  rollout is a short outage per drive. Clients see a reconnect, not a
  changed path.
- Guest-only SMB remains the identity model; the pod trusts the network it
  is exposed on. Per-user SMB authentication mapped to control-plane
  policy is still future work (ADR 0004).
- The privileged `prismfs` container is the cost of FUSE inside a pod. A
  DaemonSet with a CSI-style host mount, or a userspace SMB server reading
  the namespace directly, would remove it and can be revisited with
  operational evidence.
- Audit shipping is best effort: the queue drops the oldest events beyond
  its capacity while the control plane is unreachable, and the local log
  remains the complete record.

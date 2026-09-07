# Deploying OPAL

The control plane (Laravel, Horizon, the scheduler and Reverb) runs on the
Olsyn K3s cluster in the `opal` namespace. PrismFS is deployed
separately, on purpose — see below.

```text
deploy/k8s/web/                  base: namespace, config, four workloads, ingress
deploy/k8s/overlays/production/  namespace, replicas, image tag for CI
deploy/k8s/prismfs/              the SMB drive server, applied on its own
deploy/secrets/                  SOPS-encrypted environment
```

## What runs

| Workload | Command | Replicas | Why |
|---|---|---|---|
| `opal-web` | FrankenPHP, port 8000 | 2 | Rolling updates keep one serving |
| `opal-horizon` | `horizon` | 1 | Supervises its own workers; two would double every process count |
| `opal-scheduler` | `schedule:work` | 1 | Two schedulers fire every due task twice |
| `opal-reverb` | `reverb:start` on 8080 | 1 | Connections live in memory; see the note below |

Everything is pinned to the AWS edge node (`node-role: opal`). Postgres is
VPC-private and only that node reaches it, and the same node's instance profile
supplies the S3 credentials, so no AWS keys appear anywhere in this directory.

Hosts are `opal.olsyn.com` and `opal-ws.olsyn.com`. Both are single-label
under `olsyn.com`, so the wildcard DNS record and the existing
`olsyn-wildcard-tls` certificate already cover them: **no DNS or cert-manager
change is needed**. The namespace name matters for the same reason — the
emberstack reflector copies that certificate into namespaces matching
`olsyn-.*`, and `opal` matches. Rename the namespace and Traefik
will quietly serve its self-signed default instead.

## First deploy: what to create by hand

1. **The buckets** already exist: `olsyn-prod-materials` for the library's own
   files and `olsyn-prod-material-corpus` for the legacy corpus staged for
   ingest, both in `ap-southeast-2`. They are defined in
   `olsyn-infra/terraform/envs/prod/asset-library.tf` and are in Terraform
   state. Being under the `olsyn-prod-*` prefix, the edge instance profile
   already grants access, so no AWS keys appear anywhere in this directory.
   PrismFS reads through the `olsyn-prismfs-ops` user when it runs off that
   node; mint its access key by hand and put it in the SOPS file.

2. **The namespace and the image pull secret**:

   ```sh
   kubectl create namespace opal
   kubectl -n opal create secret docker-registry ghcr-secret \
     --docker-server=ghcr.io --docker-username=<gh-user> \
     --docker-password=<PAT with read:packages>
   ```

3. **The environment.** Copy `deploy/secrets/production.example.yaml` to
   `deploy/secrets/production.sops.yaml`, fill it in, and `sops -e -i` it. Only
   the encrypted file is committed; every other `.yaml` in that directory is
   git-ignored so a plaintext copy cannot be committed by accident.

4. **Redis.** The app expects a Redis at the host named in the ConfigMap. The
   platform's `infra/k8s/base/data` already runs one per namespace — deploy that
   into this namespace or point `REDIS_HOST` at an existing instance.

## Deploying

CI does this, and it is worth knowing in what order:

```sh
# 1. the environment (does not restart anything on its own — see below)
sops -d --output-type dotenv deploy/secrets/production.sops.yaml > .env.secrets
kubectl create secret generic opal-secrets -n opal \
  --from-env-file=.env.secrets --dry-run=client -o yaml | kubectl apply -f -
rm .env.secrets

# 2. migrations, before anything rolls, with the tag being released
sed -e "s|name: opal-migrate|name: opal-migrate-${SHA}|" \
    -e "s|:latest|:${TAG}|" deploy/k8s/web/migrate-job.yaml \
  | kubectl apply -n opal -f -
kubectl -n opal wait --for=condition=complete \
  job/opal-migrate-${SHA} --timeout=900s

# 3. the workloads
cd deploy/k8s/overlays/production
kustomize edit set image ghcr.io/geyervalmont/opal:${TAG}
kubectl apply -k .
kubectl -n opal rollout status deploy/opal-web --timeout=10m
kubectl -n opal rollout status deploy/opal-horizon --timeout=5m
kubectl -n opal rollout status deploy/opal-scheduler --timeout=5m
kubectl -n opal rollout status deploy/opal-reverb --timeout=5m
```

Check every workload, not just the web one: a crash-looping Horizon leaves jobs
piling up in Redis while the deploy reports success.

## Rollback

```sh
kubectl -n opal rollout undo deploy/opal-web
kubectl -n opal rollout undo deploy/opal-horizon
kubectl -n opal rollout undo deploy/opal-scheduler
kubectl -n opal rollout undo deploy/opal-reverb
```

Migrations do not roll back with the image. A release that changes the schema
needs to be written so the previous image still runs against the new schema,
or the rollback is a restore.

## Things that will catch you out

- **Changing a secret restarts nothing.** `kubectl apply` on a Secret updates it
  silently; the pods keep the environment they booted with. Follow it with
  `kubectl -n opal rollout restart deploy/opal-web deploy/opal-horizon deploy/opal-scheduler deploy/opal-reverb`.
- **More than one Reverb replica needs `REVERB_SCALING_ENABLED=true`.** Without
  it, a command broadcast by the pod that served the web request never reaches a
  Revit client connected to the other replica, and the failure is silent.
- **`VITE_REVERB_*` are build-time.** Vite bakes them into the JavaScript, so
  they belong to the image build. Setting them here changes nothing and the
  browser will keep trying to reach the development host.
- **The corpus ingest reads from a bucket.** See below; nothing about it needs
  local disk any more.

## Taking in the corpus

The legacy corpus is far larger than any laptop, so it is staged in
`olsyn-prod-material-corpus` and read from there. Both the files and the source
database are fetched from the bucket, so nothing has to sit on local disk.

The source is the `Material Library` folder in mcrossley's OneDrive: 40,330
files, 173 GB. The legacy database (`material_assets.sqlite`) is already in the
bucket.

### Authorising OneDrive

There is no server-to-server transfer between Microsoft and S3 — rclone always
proxies the bytes — so the point of running it in the cluster is whose
connection it proxies them through, not avoiding the copy. What cannot be moved
into the cluster is the OAuth consent, which needs a browser signed in as
someone who can read that folder. Do this once, on your own machine:

```sh
rclone config
# n) new remote
# name> onedrive                        <- must be exactly this; the job refers to it
# Storage> onedrive
# For "config type", choose the SharePoint site URL option and paste:
#   https://valmontinteriors-my.sharepoint.com/personal/mcrossley_geyervalmont_com
# Accept the browser prompt as yourself, then pick the "Documents" drive.
```

Confirm it resolves to the right folder before going near the cluster — the
first level should be the category folders (`Fabric`, `Carpet`, `Ceramic`, …):

```sh
rclone lsd "onedrive:Documents/Material Library"
```

If consent is refused, the tenant blocks rclone's default application. That
needs an Entra app registration with delegated `Files.Read.All` and
`offline_access`, whose client ID and secret `rclone config` will accept.

Then hand the token to the cluster. Keep it out of your shell history and out
of the repository — the config holds a live refresh token:

```sh
umask 077
rclone config show onedrive > /tmp/onedrive.conf
kubectl -n opal create secret generic onedrive-rclone \
  --from-file=rclone.conf=/tmp/onedrive.conf \
  --dry-run=client -o yaml | kubectl apply -f -
shred -u /tmp/onedrive.conf
```

### Staging it

1. **Fetch the files into the bucket.** This runs on the opal node, writes with
   the node's instance profile, and never touches a local disk:

   ```sh
   kubectl -n opal apply -f deploy/k8s/jobs/fetch-corpus.yaml
   kubectl -n opal logs -f job/opal-fetch-corpus
   ```

   Expect hours rather than minutes, most of it spent being throttled by
   Microsoft rather than moving bytes. It compares the destination first, so a
   killed, throttled or restarted run can simply be reapplied and will copy only
   what is still missing.

   The contents of `Material Library` land directly under `corpus/`, with no
   extra directory level, because that is what the paths in the database are
   relative to.

2. **Run the ingest**:

   ```sh
   kubectl -n opal apply -f deploy/k8s/jobs/ingest-corpus.yaml
   kubectl -n opal logs -f job/opal-ingest-corpus
   ```

It is ledgered on the source path, size and modification time, so it can be
killed and reapplied as often as needed: a rerun touches only what changed or
failed, and the summary reports ingested, unchanged, failed and bytes read. The
staged database is fetched once and reused between runs.

Watch the Quality page as it goes: materials move out of "No canonical set" as
their files land, and what is left there afterwards is genuinely absent rather
than merely unstaged.

## PrismFS

`deploy/k8s/prismfs/` is applied on its own (`kubectl apply -k deploy/k8s/prismfs`),
not from the production overlay, for three reasons worth resolving before it
goes anywhere near the cluster:

1. The pod is **privileged** — FUSE plus bidirectional mount propagation. That
   may well be refused by policy on a shared cluster, and it is a decision to
   take deliberately rather than to inherit from an overlay.
2. Its Service is a **LoadBalancer on TCP 445**. This cluster front-ends HTTP
   with Traefik and there is no evidence of MetalLB, so that address has to come
   from somewhere. SMB must also stay on a private network — never the public
   internet.
3. It cannot start until a **drive token** has been issued from the running
   control plane, which is a chicken-and-egg the app deploy should not carry.

Its namespace, registry and bucket now match the app, so when those three are
settled it drops into the same overlay with one line.

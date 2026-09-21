# Self-hosted photo material worker

OPAL stores private drafts and immutable input revisions. Its scheduler leases
an AWS GPU through Olsyn's compute broker, then creates one Kubernetes Job on
that allocation. The worker downloads a checksummed model bundle from our
private bucket, runs offline, and uploads a complete material set to the exact
revision that requested it. Callbacks use the internal OPAL service over the
cluster/tailnet connection; namespace policies permit only OPAL HTTP, DNS and
HTTPS object downloads. It never contacts a hosted inference service.

## Model installation

For an immediate ungated R&D proof of concept, use the authors' RGB→X weights:

```sh
python -m venv /tmp/opal-model-import
/tmp/opal-model-import/bin/pip install huggingface_hub==0.36.0
/tmp/opal-model-import/bin/python services/material-synthesis/bundle_models.py \
  /secure/path/opal-rgbx-v1.tar --backend rgbx --cleanup
```

Set `OPAL_SYNTHESIS_BACKEND=rgbx` and use that bundle's S3 key and checksum.
This is fully self-hosted inference; Hugging Face is used only by the one-time
model importer. The worker uses no Hugging Face credentials or hub requests.
The importer preserves the authors' research licence in the private bundle.

CHORD remains supported with `OPAL_SYNTHESIS_BACKEND=chord` (the default).
Its Hugging Face gate is automatic after account login and terms acceptance;
it does not establish that access is unavailable. Accept access to
<https://huggingface.co/Ubisoft/ubisoft-laforge-chord> in the account used for
the import, then authenticate locally with `hf auth login`. Do not put a token
in source control, a Docker build argument, or a Kubernetes worker job.

```sh
python -m venv /tmp/opal-model-import
/tmp/opal-model-import/bin/pip install huggingface_hub==0.36.0
/tmp/opal-model-import/bin/python services/material-synthesis/bundle_models.py \
  /secure/path/opal-chord-v1.tar --cleanup
aws s3 cp /secure/path/opal-chord-v1.tar \
  s3://olsyn-prod-materials/opal/models/opal-chord-v1.tar --region ap-southeast-2
```

The importer pins upstream commits, retains the CHORD licence and model
manifest, and hashes every file. It uses SD2.1 architecture/tokenizer configs
from a pinned mirror because the original and CHORD's default mirror are no
longer publicly accessible. No base-model learned weights are substituted;
the complete CHORD checkpoint supplies them. `--cleanup` additionally bundles
StableDelight's pinned FP16 weights. Omit it if cleanup is not wanted.

## Production setup

1. Run `python deploy/scripts/setup-synthesis.py` against `olsyn-edge`. This
   installs `opal-synthesis`, its restricted dispatcher role, the registry pull
   secret and the broker consumer identity. It copies secrets without printing
   or writing them locally. The broker source also admits `material-synthesis`.
2. Deploy OPAL normally. Migrations run before the web/scheduler rollout.
3. Build the worker with the **Build material synthesis worker** workflow. Use
   the resulting immutable `sha-<full-commit>` tag or registry digest.
4. Set the following values in `deploy/k8s/web/configmap.yaml`, apply and roll
   out the web and scheduler deployments. Use the importer `.sha256` file.

```yaml
OPAL_SYNTHESIS_ENABLED: "true"
OPAL_SYNTHESIS_CLEANUP_ENABLED: "true"
OPAL_SYNTHESIS_BACKEND: rgbx
OPAL_SYNTHESIS_PROFILE: aws-material-pool
OPAL_SYNTHESIS_IMAGE: ghcr.io/geyervalmont/opal-material-synthesis:sha-<full-commit>
OPAL_SYNTHESIS_MODEL_DISK: s3
OPAL_SYNTHESIS_MODEL_PATH: opal/models/opal-rgbx-v1-cloud.tar
OPAL_SYNTHESIS_MODEL_SHA256: <64-character-sha256>
```

Keep both enable flags false until the matching bundle is installed; cleanup
must stay false if the bundle omits StableDelight. Broker credentials are
injected only into the scheduler through `opal-synthesis-broker`.

The `aws-material-pool` profile uses the broker acquisition API, reuses available
AWS nodes, and tries supported regions when a fixed profile has no capacity.
It requests one L40S or RTX Pro 6000 GPU, at least 32 GiB host memory, on-demand
capacity and a maximum GPU instance price of $3.50/hour. Existing fixed AWS
profiles remain supported. Model downloads use eight bounded HTTPS range
requests, followed by whole-bundle SHA256 verification before extraction.
StableDelight's pinned custom ControlNet is included in the worker image; no
remote model code is fetched during generation.

The initial defaults are one active cloud allocation, 30 attempts per tenant
per day, and a 30-minute deadline including queue/startup time. Reconciliation
runs each minute. Cancellation closes callbacks immediately; the scheduler
deletes the job before releasing its slot. The broker controls idle-instance
shutdown/reuse, so an allocation release does not mean immediate EC2 termination.
There is deliberately no automatic retry of an ambiguous allocation request.

## Behaviour and limitations

- JPEG/PNG/WebP uploads use one authenticated request, so temporary files need
  neither public S3 access nor sticky sessions across web pods. Original photos
  and all drafts stay outside the public library File table until promotion.
- Crop, scale, resolution and cleanup changes create immutable revisions.
  Results arriving after further edits remain attached to their original
  revision. The history can restore them. Drafts can be discarded or forked.
- Existing library map sets can be copied into a private draft. Colour tint and
  roughness overrides are inexpensive local operations. Promotion creates a
  review candidate for a new material, colourway or existing variant; publishing
  continues through the existing library review/package workflow. Approving a
  repair replaces earlier approved resolutions for that surface and adopts the
  new physical scale; old LODs are not mixed back into the repaired package.
- RGB→X is an available photo-decomposition baseline, not a claim of CHORD-level
  material quality. It runs four sequential 50-step predictions. Use close,
  front-facing photographs of a single flat surface: its camera-space normals
  are interpreted as planar tangent-space normals, not a reconstructed 3D scan.
  The authors note the released metallic checkpoint differs from their paper.
  Sources: https://github.com/zheng95z/rgbx and https://huggingface.co/zheng95z/rgb-to-x.
- Both backends estimate base colour, normals, roughness and metallic. Height is
  **relative 16-bit relief integrated from normals**, not measured displacement.
  Normal output is converted to OpenGL convention. Single-photo estimates and
  optional glare cleanup need visual assessment on real surfaces.
- Perspective rectification, guaranteed seamless tiling, HEIC conversion,
  calibrated physical capture, automatic map merging, high-resolution detail
  recovery and persistent warm model processes are later work. No AI upscaling
  is presented as measured detail. Cold starts include image/model downloads.
- Discard hides a draft and cancels its running jobs. Private files are retained
  in this R&D version; no age-based purge is installed because forks share them.
- Direct apply uses the existing private Studio → Revit preview path. That
  consumer currently uses width as its tile scale; use square physical samples
  for accurate direct application. Library candidates retain both dimensions.

## Validation

```sh
docker build -t opal-material-synthesis:dev services/material-synthesis
docker run --rm --entrypoint python \
  -v "$PWD/services/material-synthesis:/test" -w /test \
  opal-material-synthesis:dev -m unittest discover -s tests -v
```

The PHP `PhotoStudioTest` covers owner isolation, immutable inputs, late results,
tokens, quotas, map integrity, 16-bit height, promotion, uploads and repair forks.
CPU tests do not establish inference quality or performance. Validate a real
GPU photo job after installing a matching model bundle before enabling broad use.

## Studio feedback and iteration

The draft browser includes private 160 px thumbnails, name/ID search, pagination,
state filters and revision counts. Version history supports read-only comparisons
and non-destructive restore. Prepared photos can be compared directly with the
resulting base colour at full resolution. Brightness, saturation, tint and an
explicit aligned-photo blend are saved as finish revisions without another GPU
run; structural maps are retained. Photo blending restores photographed lighting
as well as appearance and should be judged in the material preview.

Generation shows queue/startup/model-loading stages, elapsed time, worker heartbeat
age, intermediate photo previews and map counts. Counters represent completed maps
within a stage, not an estimated percentage of total wall time. Deploy the web app
and rebuild the synthesis worker to get RGB→X estimation counts and upload counts;
older workers continue to report coarse stages. CHORD inference remains
indeterminate until upload. Editing the current draft does not hide an older
revision's active run, and completed results remain accessible in version history.

The size selector describes output map dimensions, not super-resolution. Production
draft `0fa9c` (revision 36) used RGB→X with a 59% crop of a 2048 px source, 1024 px
output, and cleanup disabled. Its aligned input was already soft; the inferred
base colour lost additional fine grain. This UI update does not add a learned
upscaler or establish improved inference quality. A future detail-recovery stage
needs evaluation of the complete, aligned PBR map set.

# Self-hosted photo-to-material proof — 18 September 2026

[Open the private Studio proof](https://opal.olsyn.com/materials/studio/photos?draft=e0b9ca36-cfb0-4da7-ba0f-0772e9c39907)
(owner: Harrison, Olsyn workspace).

## What ran

- Authors' [RGB→X](https://github.com/zheng95z/rgbx) checkpoint, downloaded from
  [zheng95z/rgb-to-x](https://huggingface.co/zheng95z/rgb-to-x), revision
  `b38b3fd73a14ea62f3953fc54bc4ac67b067bae0`.
- StableDelight cleanup at 50%, followed by four 50-step material predictions.
- A 1024 × 1024 [painted-brick photograph from CHORD's example set](https://github.com/ubisoft/ubisoft-laforge-chord/blob/675a29d1fee5e1b256833bd4edabd619be43d0c7/examples/in_the_wild/wild_5.jpg).
- Base colour, OpenGL normals, roughness, metallic, and relative 16-bit height
  integrated from normals. Prepared and cleaned photos are also retained.
- Our AWS RTX Pro 6000 Blackwell Server Edition, 95 GiB reported VRAM. No
  external inference service; runtime model-hub access is disabled. Models are
  served from our private S3 bucket and verified by SHA256 before loading.

Successful generation: `a6ac8e58-d470-410a-ab1f-818dcea1cbe2`, revision 23.
Worker image: `ghcr.io/geyervalmont/opal-material-synthesis:sha-6cde52f6e753887e8dc0056a99c2bcb9253be027`.
Bundle: `opal/models/opal-rgbx-v1-cloud.tar`.
SHA256: `b21ce99d984813981596845ab43230eccdbcd7cfd57a571159714bda41675f7d`.

## Measurements and checks

| Measurement | Observed |
| --- | --- |
| Material model stage, including local model loading | 7.72 s |
| Entire worker, including model download/unpacking, cleanup and uploads | 171.24 s |
| Peak allocated GPU memory | 5.54 GiB |
| Output | All five maps at 1024 × 1024; height PNG is uint16 |
| Studio viewer | Ready, all five maps loaded |
| Mobile layout | No horizontal overflow at 390 px |

These are one measured run, not a throughput guarantee. Provisioning, container
image pulls and queue time are additional to worker elapsed time. Eight bounded
range downloads improved startup; persistent model caching is still future work.
Exported PNGs were checked against the hashes recorded by OPAL.

The fixed L40S and A10G profiles encountered AWS capacity shortages during setup.
`aws-material-pool` now uses the broker's regional acquisition API, with one GPU,
at least 32 GiB host memory and a $3.50/hour GPU instance price ceiling. It can
reuse warm/stopped AWS capacity. On-prem allocations are rejected.

## What the proof does and does not establish

This validates the complete photo → cleanup → material maps → private Studio
revision → interactive preview path. It is not a material-quality benchmark.
The model produced useful brick normals, but its metallic estimate was unsuitable
for painted brick (mean about 0.54). Studio now has an instant metalness override:
use 0 for paint/stone/timber/fabric and 1 for bare metal. Earlier inferred maps
remain in revision history; this correction does not run the GPU again.

The albedo largely neutralized the source's blue appearance, so colour fidelity
needs assessment on known real samples. Roughness and metalness are estimates,
height is relative rather than measured, and seamless tiling/perspective
rectification are not implemented. A live Revit client was not part of this check.

CHORD is still available through its [official Hugging Face account/terms gate](https://huggingface.co/Ubisoft/ubisoft-laforge-chord).
Its gate is automatic, not evidence that access cannot be obtained. RGB→X avoids
waiting for an authenticated account. Model/source licences remain bundled for
this internal research workflow; no commercial permission is claimed.

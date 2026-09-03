# OPAL for Revit

The Revit side of the library: a pyRevit extension that applies library
materials from the mounted OPAL drive, plus a Unix harness that runs the same
code without Revit.

```
opal_client/          Revit-independent logic (IronPython 2.7 + CPython 3)
  api.py              OPAL API v1 client (bearer token, stdlib HTTP)
  drive.py            drive path → local mount / UNC path, presence and hash checks
  host.py             Host interface; UnixHost keeps a "project" as JSON
  workflow.py         sync / plan / apply — what the ribbon buttons do
opal_cli.py           the workflow from a shell (check, seed, sync, plan, apply)
unix_proxy.sh         mounts a drive with PrismFS and runs the whole loop
tests/                unittest suite for the workflow (python3 -m unittest discover -s tests)
pyrevit/OPAL.extension/
  lib/opal_revit.py   RevitHost: appearance-asset textures, identity parameters, face picking
  lib/opal_client/    copy of the package above (kept in step by the installer)
  OPAL.tab/Library    Sync · Apply Selected · Resolve Clicked
  OPAL.tab/Review     Edit Visible Materials · Object Material Cleaner (Matt's tools, unchanged)
install_pyrevit_extension.ps1
config.example.json
```

## How a material gets into Revit

1. **Resolve.** The client tries the Revit material's references in order:
   its UniqueId (a registered identity), then the Description/Keywords/Model/
   Manufacturer parameters (an embedded variant code), then its name.
   `GET /api/v1/variants/resolve?platform=revit&reference=…` never guesses.
2. **Plan.** `GET /api/v1/variants/{code}/paths?drive=…` lists the variant's
   published files on that drive. The client prefers the `revit` target
   (base colour, bump, glossiness) and falls back to the canonical `pbr` set,
   highest quality unless one is asked for. Every file is checked on the
   mount and hashed against the library before anything is written.
3. **Apply.** Texture paths on the appearance asset are pointed at the drive
   (the asset is duplicated first if other materials share it), the
   real-world scale is set from the variant's tile width, the variant code is
   written into Description/Model/Manufacturer/Keywords, and
   `POST /api/v1/variants/{code}/identities` registers the Revit UniqueId so
   the next Sync resolves it directly.

Nothing is copied: Revit reads the textures from the read-only drive, so a
republish in the library shows up in the model on the next render.

## Testing on Linux ("unix proxy")

```
cd services/prismfs && cargo build
export OPAL_TOKEN=…         # Settings → API tokens
export OPAL_DRIVE_TOKEN=…   # Drives → issue token for studio-share
integrations/revit/unix_proxy.sh CPT-TARKETT-ACADEMIX-ASHEN
```

The script mounts the drive with PrismFS (FUSE, manifest polled from the
control plane), seeds a JSON project standing in for a Revit document, runs
sync → apply → sync, reads the applied texture back through the mount and
compares its hash, and reports the PrismFS audit lines. For SMB, the
`make prism-e2e` stack in `services/prismfs` exports the same mount through
Samba; point `--mount` at a CIFS mount of it and the CLI behaves the same.

Unit tests need no services: `python3 -m unittest discover -s integrations/revit/tests`.

## Installing in Revit

1. Install pyRevit.
2. `powershell -File install_pyrevit_extension.ps1` copies the extension to
   `%APPDATA%\pyRevit\Extensions\OPAL.extension` and writes a starter config.
3. Edit `%APPDATA%\OPAL\config.json`: API base URL, your token, the drive slug
   and the drive's mount letter or UNC root (`M:\` or `\\server\opal`).
4. pyRevit → Reload. The OPAL tab appears.

The extension has not yet been exercised inside Revit (a Windows VM is being
set up); the Revit host follows the appearance-asset editing pattern from
Matt's WF07 scripts (`AppearanceAssetEditScope`, `UnifiedBitmap` connected
assets, unit-converted real-world scale).

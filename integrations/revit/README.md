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
  realtime.py         Pusher-protocol client over a stdlib or .NET WebSocket
  agent.py            session + command loop: the web app drives the client
  link.py             account linking (device code) and the saved config
opal_cli.py           the workflow from a shell (check, seed, sync, plan, apply, link, agent)
unix_proxy.sh         mounts a drive with PrismFS and runs the whole loop once
unix_agent.sh         mounts a drive and stays connected as an agent
tests/                unittest suite (python3 -m unittest discover -s tests)
pyrevit/OPAL.extension/
  startup.py          connects to the library at load when the machine is linked
  lib/opal_revit.py   RevitHost (appearance assets, identity parameters, face picking)
                      and the agent runner (ExternalEvent bridge to the Revit thread)
  lib/opal_client/    copy of the package above (kept in step by the installer)
  OPAL.tab/Account    Connect · Status
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

## Linking and live commands

Nobody pastes tokens. **Connect** asks the library for a link code
(`POST /api/v1/link`), shows it, and opens the verify page; the user enters
the code while signed in to the web app; the client polls
`GET /api/v1/link/{code}` until the link is claimed and receives an API token
and the realtime connection details, saved to the config file
(`%APPDATA%\OPAL\config.json` on Windows, `~/.config/opal/config.json`
elsewhere).

From then on the client runs an **agent**: it registers a session
(`POST /api/v1/sessions`), subscribes to the session's private channel on
the realtime server (Laravel Reverb, Pusher protocol, one WebSocket), catches
up on commands queued while it was offline, and executes what the web app
sends: `apply {variant, quality?, material_id?}`, `sync`, `resolve`. Each
command is acknowledged, executed through the same `Workflow` the buttons
use, and answered with a result or a failure message. Heartbeats every 30 s
keep the session listed as online.

Inside Revit the WebSocket lives on a background thread; it only queues the
command and raises an `ExternalEvent`. Revit calls the handler on its own
thread when idle, which is the only place the Revit API may be used, and the
handler runs the executor against the active document. Revit is
single-threaded for API purposes, but a socket that merely wakes it up is
fine.

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

For the live loop:

```
integrations/revit/opal_cli.py link          # once; prints the code, saves the config
OPAL_DRIVE_TOKEN=… integrations/revit/unix_agent.sh
```

then open a material in the web app and choose this machine under "Apply
in …". The agent prints each command and its result; the JSON project under
`.unix-proxy/` shows the applied textures.

Unit tests need no services: `python3 -m unittest discover -s integrations/revit/tests`
(the realtime tests run an in-process WebSocket server).

## Installing in Revit

1. Install pyRevit 6.5.5 or later (Revit 2027 needs 6.4+):
   `pyRevit_6.5.5.26237_signed.exe` (per user) from the pyRevit GitHub
   release, plus `pyRevit_CLI_6.5.5.26237_signed.exe` if you want the CLI.
2. `powershell -File install_pyrevit_extension.ps1` copies the extension to
   `%APPDATA%\pyRevit\Extensions\OPAL.extension`; with `-Register` it instead
   adds this folder's `pyrevit` directory to pyRevit's extension search paths
   (needs the CLI) so edits show up on the next Reload without copying.
3. Map the drive (`net use M: \\server\opal`) and, if the web app is not on a
   public name, add it to `C:\Windows\System32\drivers\etc\hosts`.
4. pyRevit → Reload. The OPAL tab appears. Press **Connect**, enter the code
   in the web app, done; **Status** shows the session.

## Dev loop with the Windows VM

The VM (`win11`, libvirt) sees the whole repo through a virtiofs share of
`/home/harrison/code` tagged `codeshare`. With the virtio-win guest tools and
WinFsp installed, the share appears as a drive letter (usually `Z:`; check
File Explorer or `Get-PSDrive -PSProvider FileSystem`). Point pyRevit at the
extension on that share instead of copying it:

```
pyrevit extensions paths add Z:\olsyn-asset-library\integrations\revit\pyrevit
pyrevit extensions enable OPAL      # if it shows as disabled
```

(or Settings → Custom Extension Directories in the pyRevit UI). Then the loop
is: edit on Linux, pyRevit → Reload in Revit. `startup.py` reconnects the
agent on every reload.

Networking from the VM: the control plane is `http://asset-library.test`
with a hosts entry pointing at `192.168.122.1` (plain HTTP; a bridge on the
host publishes 80 for the web app, 8080 for Reverb and 445 for the drive),
and the drive is `\\192.168.122.1\opal` (`make prism-drive-up` with
`PRISMFS_DRIVE_BIND=192.168.122.1`). Set `"api": "http://asset-library.test"`
when linking; the realtime block the link returns carries the Reverb host
and port.

The extension has not yet been exercised inside Revit; the Revit host follows
the appearance-asset editing pattern from Matt's WF07 scripts
(`AppearanceAssetEditScope`, `UnifiedBitmap` connected assets, unit-converted
real-world scale). Things to confirm in the VM first: that `startup.py` runs
with `lib/` importable, that `UI.ExternalEvent.Create` works from `startup.py`
under pyRevit 6, that `ClientWebSocket` is available to the IronPython engine
on .NET 8, that `PickObject` may be called from an external event, and that
`forms.alert` can be shown from the link thread.

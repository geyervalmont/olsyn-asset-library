# OPAL for Revit

The native OPAL connector targets Revit 2027 and does not require pyRevit or a
separate .NET runtime. Revit 2027 supplies the .NET 10 runtime used by the
add-in.

## User flow

1. Download `OPAL-Revit-Setup.exe` from **OPAL → Settings → Revit extension**.
2. Run the per-user installer and restart Revit if it was open.
3. In Revit, open **OPAL → Settings**, choose **Connect account**, and complete
   the short-code flow in the browser.
4. Set the mounted OPAL drive path, then use **Apply Selected** or **Sync**.

The settings window shows the active account, server connection, document and
update state. Choose **Change account** to disconnect and relink. Click the
version line five times to reveal developer-only server URL and release-channel
controls; this permits local HTTP endpoints without presenting them to normal
users.

## Updates

The main add-in is installed into a versioned directory. Automatic updates are
downloaded from OPAL, SHA-256 verified, safely extracted and recorded as
pending. The small bootstrap activates a pending version the next time Revit
starts, avoiding writes to assemblies Revit has loaded.

The development channel is rebuilt on every push to `main`. Production can be
promoted separately to the `revit-stable` GitHub release tag.

## Build

On Windows with the .NET 10 SDK and Inno Setup 6:

```powershell
./build-release.ps1 -Version 0.1.0.1 -OutputDirectory artifacts/revit
```

This runs the portable client tests and emits the installer, update package and
release manifest. CI can optionally Authenticode-sign the DLLs and installer by
supplying `REVIT_SIGNING_CERTIFICATE_BASE64` and
`REVIT_SIGNING_CERTIFICATE_PASSWORD` repository secrets.

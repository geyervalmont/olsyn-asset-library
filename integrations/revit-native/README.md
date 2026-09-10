# OPAL for Revit

The native OPAL connector targets Revit 2025, 2026 and 2027 and does not require
pyRevit or a separately installed .NET runtime. One shared feature codebase is
compiled against the exact API and runtime profile for each Revit year.

## User flow

1. Download `OPAL-Revit-Setup.exe` from **OPAL → Settings → Revit extension**.
2. Run the per-user installer. It detects installed Revit versions and lets you
   select additional versions when Revit uses a custom installation path.
3. In Revit, open **OPAL → Settings**, choose **Connect account**, and complete
   the short-code flow in the browser.
4. Set the mounted OPAL drive path, then use **Apply Selected** or **Sync**.

The settings window shows the active account, server connection, document and
update state. Choose **Change account** to disconnect and relink. Click the
version line five times to reveal developer-only server URL and release-channel
controls; this permits local HTTP endpoints without presenting them to normal
users.

## Updates

Each Revit year has its own bootstrap, versioned binary directory and exact-year
update feed. Account, server and drive settings are shared. Automatic updates
are downloaded from OPAL, SHA-256 verified, safely extracted and recorded as
pending. The small bootstrap activates a pending version the next time that
Revit release starts, avoiding writes to assemblies Revit has loaded and
preventing one Revit build from overwriting another.

The first matrix-aware 2027 package also recognises the old single-version
layout. Existing 2027 clients can therefore take the automatic update safely;
running the universal installer later migrates their bootstrap and state into
the year-scoped layout.

The development channel is rebuilt on every push to `main`. Production can be
promoted separately to the `revit-stable` GitHub release tag.

## Build

On Windows with the .NET 8 and .NET 10 SDKs and Inno Setup 6:

```powershell
./build-release.ps1 -Version 0.1.0.1 -OutputDirectory artifacts/revit
```

This runs the portable client tests, compiles every row in
`../../apps/web/config/revit-versions.json`,
and emits one universal installer plus an exact update package and manifest per
Revit year. CI can optionally Authenticode-sign the DLLs and installer by
supplying `REVIT_SIGNING_CERTIFICATE_BASE64` and
`REVIT_SIGNING_CERTIFICATE_PASSWORD` repository secrets.

## Adding compatibility

Add one entry to `../../apps/web/config/revit-versions.json` with the Revit
year, API package version, target frameworks and runtime. The release build,
installer choices, download page, packages, manifests and CI compilation gate
all derive from that file. Feature code stays in `Opal.Revit`; isolate a genuine
Autodesk API break behind a small adapter instead of copying commands or UI code
into a version-specific project. Each build also defines `REVIT_<year>` (for
example `REVIT_2027`) for the rare API call that genuinely needs a compile-time
compatibility branch.

Revit 2027 is verified in a real host. The 2025 and 2026 outputs are compiled
against their exact API reference packages and are marked beta until they have
also passed a host smoke test.

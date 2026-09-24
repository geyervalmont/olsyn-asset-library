# OPAL Drive for Windows

OPAL Drive mounts the signed-in person's visible materials as a real Windows drive over HTTPS. Revit, Omniverse and Explorer open ordinary paths such as `O:\materials\by-id\<material UUID>\<variant UUID>\v1\…`. The drive includes published texture projections and canonical USDZ packages. Names may change without changing these paths.

## Install and connect

1. Install **OPAL-Drive-Setup.exe** from OPAL → Connect. Windows 10/11 x64 is supported. The installer requires administrator rights to install the bundled, upstream-signed Dokany filesystem driver. IT can deploy the installer once for the machine.
2. Open **OPAL Drive** from Start. Choose a free drive letter (default `O:`), then **Connect account** and approve the displayed code in your browser.
3. Open the mounted folder. Keep OPAL Drive running in the system tray. It starts at Windows sign-in and reconnects automatically unless automatic mounting is disabled. Close the window to leave it running; choose **Quit and unmount** in the tray to stop it.
4. Use the same drive letter across the team for portable material references. OPAL never silently substitutes a different letter when yours is occupied.

Opening OPAL Drive again from Start restores its existing window, including when it is hidden in the tray or minimized. Windows sign-in also shows the connection window until an account has been connected; subsequent sign-ins reconnect in the background.

## Status and troubleshooting

Open **Status & diagnostics** from the main window or tray menu. Status shows the current connection step, Windows mount and library availability, last successful library refresh and heartbeat, file/upload counts, next retry, and the last failure. The warning now identifies the failed operation and distinguishes DNS, TLS, proxy authentication, account access, server responses, local storage and Windows mount failures.

Use **Run checks** to test local storage, the installed driver, drive-letter availability, DNS/system proxy selection, HTTPS, account access, the complete library manifest, and one authorized 64-byte file read. Checks are read-only apart from a temporary local storage probe and diagnostics logs; they do not change the account, mount, or uploads. Cancel is available while checks run. A DNS failure can coexist with successful HTTPS when a company proxy resolves names remotely.

Use **Copy report** or **Save report** to share status, timed check results, and recent activity with support. Reports include the app/Windows/runtime versions, server origin and device ID; they exclude credentials, browser sign-in codes, email addresses, material filenames, HTTP bodies and exception messages. Nothing is uploaded automatically. Structured logs survive restarts under `%LOCALAPPDATA%\Olsyn\OPAL\Drive\logs`; rotation retains two approximately 256 KiB files and reports include at most 200 events. Logging failures do not stop the drive.

Version 0.1.2 fixes a native security-buffer sizing error in 0.1.0–0.1.1 that prevented mounting after successful browser approval and was misleadingly reported as a network problem. Upgrade the installer; the existing account connection is retained. The Windows mount test uses the same per-user security configuration as the application.

Updates currently use the installer from Connect. The fixed installer identity updates the existing Program Files installation and keeps account settings, caches and staged uploads in the user's profile. There is no automatic software updater in OPAL Drive yet; automatic reconnect at Windows sign-in is separate.

Revit and Omniverse releases with drive discovery automatically find the mount when signed into the same OPAL server and account. Earlier releases can use it by entering `O:\` as the material root in their settings. Each application approves its own device connection; no bearer token is copied between apps.

## Private Incoming folders

Contributors prepare a batch on Connect. Only that person's open batches appear in `Incoming`. Copy ordinary files and subfolders into a batch. File copying stages durable bytes locally; the tray window separately shows **pending**, **confirmed**, and **awaiting retry** counts. Wait for confirmation in both the app and Connect before relying on the upload. Interrupted uploads retry their complete bytes and checksum with the same reservation.

Published materials and the drive root are read-only. Incoming is an append-only staging area: rename, delete and replacement of reserved files are not supported. Use a new name or batch. Empty files are not uploaded. There is no ingestion processor or automatic material publication. The website lets you close or submit a completed batch; submission means waiting for the future processor.

Staging is limited to 256 MiB per file, 500 files per batch and 2 GiB on this computer. Staged payloads and queue records survive restarts and sign-out. They are intentionally retained, including closed batches, rather than silently deleting source files. Recover them from `%LOCALAPPDATA%\Olsyn\OPAL\Drive\accounts\<partition>\uploads` (`*.json` records identify their `*.data` payloads). Remote completed uploads are not downloaded back into Incoming on another computer.

## Network, security and caching

- Runtime file traffic uses **opal.olsyn.com:443** and the existing browser identity-provider domains. No VPN, inbound ports, public SMB or object-storage credentials are required.
- Windows system proxy settings and trusted certificates apply. The HTTP client supplies the current Windows identity for proxy authentication and never disables TLS checks or follows API redirects. Validate PAC, proxy authentication and TLS inspection with your IT policy.
- The mounted drive belongs to the current Windows session and has a user/SYSTEM-only security descriptor. Tokens are protected with user-scoped DPAPI and bound to the server origin. Namespace/cache partitions include server and account ID.
- Every new material handle checks access over HTTPS. Open-handle authorization is refreshed at most every 15 seconds when reading. Namespace refresh and heartbeat run every 30 seconds. Network failures, expired metadata or observed revocation block reads; this is not an offline drive. Already-returned Windows memory pages or separately copied files cannot be recalled.
- Files download on first read into a bounded 4 GiB cache, up to 1 GiB per file. Downloads use checked HTTP ranges, verify the complete SHA-256, then commit atomically. Random reads use the verified local file. Open files are pinned against eviction. The first read of a large package waits for its complete verified download.
- Sign-out unmounts and revokes the device token. Connect can revoke it remotely. Cached and staged files are retained in the user's Windows profile. The app advertises a mount only after Windows confirms it; the website treats stale heartbeats as offline.

## Build and release

`dotnet run --project tests/Opal.Drive.Tests -c Release` tests the portable core. On Windows with Dokany installed, `dotnet run --project tests/Opal.Drive.MountTest -c Release` mounts a real drive against a local HTTP fixture and exercises enumeration, sequential/random/memory-mapped reads, read-only protection, Incoming copy/readback/upload, revocation and unmount.

The independent **Windows drive** GitHub Actions workflow installs and verifies pinned Dokany 2.3.1.1000, runs both test suites and packages a self-contained .NET 10 app with Inno Setup. Push `drive/vMAJOR.MINOR.PATCH` to publish an immutable installer, checksum manifest and `drive-latest` compatibility alias. Connect discovers tagged releases automatically. OPAL signing uses the existing signing secrets when configured; the release manifest reports whether the OPAL installer is signed. The bundled driver installer is always checksum- and signature-verified upstream software.

Managed deployment: `OPAL-Drive-Setup.exe /VERYSILENT /SUPPRESSMSGBOXES /NORESTART`. It installs under Program Files, registers Windows sign-in launch for each user and leaves account approval to that user. Restart if the driver installer requires it. Do not run the tray app elevated for normal use. Uninstall removes the application/startup registration, retains user caches and staged files, and leaves the shared Dokany driver installed for other software.

A GitHub Windows smoke test is not certification of every enterprise image, endpoint-security policy or design host. Validate the designer's actual Revit and Kit applications against the mounted paths before a wider rollout.

# OPAL Drive for Windows

OPAL Drive mounts the signed-in person's visible materials as a real Windows drive over HTTPS. Revit, Omniverse and Explorer open ordinary paths such as `O:\materials\by-id\<material UUID>\<variant UUID>\v1\…`. The drive includes published texture projections and canonical USDZ packages. Names may change without changing these paths.

For browsing in Explorer, use `O:\materials\by-name\<category>\<material name>\<variant name>\revit\<quality>\…` or `canonical\material.usdz`. These read-only aliases show the latest published version and converter output with the same access checks and content cache. Names are made safe for Windows; ambiguous labels receive an identity suffix. Renames or publishing a new version update this browsing view. Keep references saved by Revit, Omniverse and the RVT → USD workflow on the immutable `by-id` paths. Existing 0.1.2 clients receive this folder on their next library refresh after the server update.

## Install and connect

1. Install **OPAL-Drive-Setup.exe** from OPAL → Connect. Windows 10/11 x64 is supported. The installer requires administrator rights to install the bundled, upstream-signed Dokany filesystem driver. IT can deploy the installer once for the machine.
2. Open **OPAL Drive** from Start. Choose a free drive letter (default `O:`), then **Connect account** and approve the displayed code in your browser.
3. Open the mounted folder. Keep OPAL Drive running in the system tray. It starts at Windows sign-in and reconnects automatically unless automatic mounting is disabled. Close the window to leave it running; choose **Quit and unmount** in the tray to stop it.
4. Use the same drive letter across the team for portable material references. OPAL never silently substitutes a different letter when yours is occupied.

Opening OPAL Drive again from Start restores its existing window, including when it is hidden in the tray or minimized. Windows sign-in also shows the connection window until an account has been connected; subsequent sign-ins reconnect in the background.

## Status and troubleshooting

Open **Status & diagnostics** from the main window or tray menu. Status shows the current connection step, Windows mount and library availability, last successful library refresh and heartbeat, file/upload counts, next retry, and the last failure. The warning now identifies the failed operation and distinguishes DNS, TLS, proxy authentication, account access, server responses, local storage and Windows mount failures.

Use **Run checks** to test local storage, the installed driver, drive-letter availability, DNS/system proxy selection, HTTPS, account access, the complete library manifest, and one authorized 64-byte file read. Checks are read-only apart from a temporary local storage probe and diagnostics logs; they do not change the account, mount, or uploads. Cancel is available while checks run. A DNS failure can coexist with successful HTTPS when a company proxy resolves names remotely.

Use **Copy report** or **Save report** to share status, timed check results, and recent activity with support. Reports include the app/Windows/runtime versions, server origin and device ID; they exclude credentials, browser sign-in codes, email addresses, material filenames, HTTP bodies and exception messages. Manual connection checks stay local. Structured logs survive restarts under `%LOCALAPPDATA%\Olsyn\OPAL\Drive\logs`; rotation retains two approximately 256 KiB files and reports include at most 200 events. Logging failures do not stop the drive.

### Automatic error reporting (0.1.3+)

**Send error codes and app versions to OPAL support** is enabled by default after account connection. It sends operational errors and transitions to a ready drive to the same OPAL server over authenticated HTTPS. Reports contain a random event ID, device ID, app/Windows versions, stage, error category, HRESULT/HTTP/Win32/Dokan status codes and elapsed time. There are no exception messages, stacks, URLs, filenames, material contents or credentials in the payload. The server associates the report with the authenticated account.

The background worker keeps up to 200 reports for 14 days in `logs/telemetry-outbox.json`, scoped to the server, device and current credential using a one-way hash. Identical errors are sampled once per five minutes. Batches contain at most 20 reports; retries back off from 15 seconds to 15 minutes with jitter and a five-second network timeout. Acknowledged event IDs prevent duplicate ingestion. The filesystem and UI do not wait for telemetry. Storage failures fall back to the bounded in-memory queue.

**Status & diagnostics** shows queue/delivery status. Turning reporting off cancels pending delivery and clears the local queue; sign-out or a different credential also clears it. Database history expires after 30 days; structured server logs follow the deployment’s log retention. Manual diagnostic checks are never automatically uploaded. Failures before account connection, an unusable/revoked token, process termination before queue persistence, and computers that cannot reach OPAL require a manually shared report; this is not native crash-dump collection.

OPAL **Connect → Drive health & error history** shows each person's reports and recent device heartbeats. Super-admins can inspect all accounts, filter a device ID and see the most frequent errors by app version over 24 hours. The page refreshes every 15 seconds; stale heartbeats are labelled offline, not inferred failures. New error events also emit the structured server log `drive.client_error`. No email or Slack notifications are configured. `opal:drive:prune-telemetry` runs daily for retention. The API accepts only allowlisted fields/codes, with a 32 KiB request limit and 12 batches/minute/account.

Version 0.1.2 fixes a native security-buffer sizing error in 0.1.0–0.1.1 that prevented mounting after successful browser approval and was misleadingly reported as a network problem. Upgrade the installer; the existing account connection is retained. The Windows mount test uses the same per-user security configuration as the application.

Updates currently use the installer from Connect. The fixed installer identity updates the existing Program Files installation and keeps account settings, caches and staged uploads in the user's profile. There is no automatic software updater in OPAL Drive yet; automatic reconnect at Windows sign-in is separate.

Revit and Omniverse releases with drive discovery automatically find the mount when signed into the same OPAL server and account. Earlier releases can use it by entering `O:\` as the material root in their settings. Each application approves its own device connection; no bearer token is copied between apps.

## Upload folder and ingestion queue (0.1.4+)

Contributors automatically get `upload` at the root of the drive. Copy ordinary files or nested folders there, then use the website's **Ingestion** page to name and queue the batch for review. Original names and paths are preserved. Legacy prepared batches remain accessible under `Incoming`. The root upload folder rotates to a new private batch after the previous one is queued or closed. File copying stages durable bytes locally; the tray window separately shows **pending**, **confirmed**, and **awaiting retry** counts. Wait for confirmation in both the app and Ingestion before relying on the upload. Interrupted uploads retry their complete bytes and checksum with the same reservation.

Published materials and the drive root are read-only. Upload is an append-only staging area: rename, delete and replacement of reserved files are not supported. Use a new name or batch. Empty files are not uploaded. There is no ingestion processor or automatic material publication. The website lets you close or queue a completed batch; queueing shares its files with reviewers in the same workspace and waits for the future processor. Before submission the batch stays private. No file parsing runs.

Staging is limited to 256 MiB per file, 500 files per batch and 2 GiB on this computer. Staged payloads and queue records survive restarts and sign-out. They are intentionally retained, including closed batches, rather than silently deleting source files. Recover them from `%LOCALAPPDATA%\Olsyn\OPAL\Drive\accounts\<partition>\uploads` (`*.json` records identify their `*.data` payloads). Remote completed uploads are not downloaded back into Incoming on another computer.

## Network, security and caching

- Runtime file traffic uses **opal.olsyn.com:443** and the existing browser identity-provider domains. No VPN, inbound ports, public SMB or object-storage credentials are required.
- Windows system proxy settings and trusted certificates apply. The HTTP client supplies the current Windows identity for proxy authentication and never disables TLS checks or follows API redirects. Validate PAC, proxy authentication and TLS inspection with your IT policy.
- The mounted drive belongs to the current Windows session and has a user/SYSTEM-only security descriptor. Tokens are protected with user-scoped DPAPI and bound to the server origin. Namespace/cache partitions include server and account ID.
- Every new material handle checks access over HTTPS. Open-handle authorization is refreshed at most every 15 seconds when reading. Namespace refresh and heartbeat run every 30 seconds. Network failures, expired metadata or observed revocation block reads; this is not an offline drive. Already-returned Windows memory pages or separately copied files cannot be recalled.
- Files download on first read into a bounded 4 GiB cache, up to 1 GiB per file. Downloads use checked HTTP ranges, verify the complete SHA-256, then commit atomically. Random reads use the verified local file. Open files are pinned against eviction. The first read of a large package waits for its complete verified download.
- Sign-out unmounts and revokes the device token. Connect can revoke it remotely. Cached and staged files are retained in the user's Windows profile. The app advertises a mount only after Windows confirms it; the website treats stale heartbeats as offline.

## Build and release

`dotnet run --project tests/Opal.Drive.Tests -c Release` tests the portable core. On Windows with Dokany installed, `dotnet run --project tests/Opal.Drive.MountTest -c Release` mounts a real drive against a local HTTP fixture and exercises enumeration, sequential/random/memory-mapped reads, read-only protection, root upload copy/readback/upload, revocation and unmount.

The independent **Windows drive** GitHub Actions workflow installs and verifies pinned Dokany 2.3.1.1000, runs both test suites and packages a self-contained .NET 10 app with Inno Setup. Push `drive/vMAJOR.MINOR.PATCH` to publish an immutable installer, checksum manifest and `drive-latest` compatibility alias. Connect discovers tagged releases automatically. OPAL signing uses the existing signing secrets when configured; the release manifest reports whether the OPAL installer is signed. The bundled driver installer is always checksum- and signature-verified upstream software.

Managed deployment: `OPAL-Drive-Setup.exe /VERYSILENT /SUPPRESSMSGBOXES /NORESTART`. It installs under Program Files, registers Windows sign-in launch for each user and leaves account approval to that user. Restart if the driver installer requires it. Do not run the tray app elevated for normal use. Uninstall removes the application/startup registration, retains user caches and staged files, and leaves the shared Dokany driver installed for other software.

A GitHub Windows smoke test is not certification of every enterprise image, endpoint-security policy or design host. Validate the designer's actual Revit and Kit applications against the mounted paths before a wider rollout.

The complete server contract and scope are documented in [drive-upload-queue.md](../../docs/drive-upload-queue.md). The shared Nucleus/SMB library mount remains read-only.

Drive 0.1.7 requests layout 2: `O:\ingestion\upload` receives source files and
`O:\ingestion\workspace` is reserved for the upcoming preparation pipeline.
The published `O:\materials` tree is unchanged. Existing staged uploads retain
their payload keys and batch IDs when moving from `O:\upload`; older clients
continue to receive the layout 1 alias from the same server inbox.

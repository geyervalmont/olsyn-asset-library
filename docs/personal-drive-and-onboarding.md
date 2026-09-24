# Personal drive transport and onboarding

The `/connect` page is the entry point for designers: download the current Revit
installer, approve the browser connection, then check device and material-folder
status. `/connect/it` contains deployment and network notes. Primary navigation is
Library, Create and Connect; review, jobs and drive administration are grouped
under Manage. Contributor-only upload batches are explicitly marked as a preview.

## What is delivered

- A user-scoped, versioned HTTPS namespace and authenticated single-range file
  transport. It uses the existing material visibility rules and published,
  immutable derivative paths. It does not hand clients S3 keys or credentials.
- Private intake sessions with UUIDv7 identifiers, bounded file reservations,
  checksum-verified staging, whole-file retry, expiry, cancellation and submission.
- Device heartbeats, token revocation, and a shared .NET transport
  (`OpalDriveClient`) used by the Windows filesystem adapter.
- Revit heartbeat support for the configured material folder being available or
  missing. This is a directory availability check, not a verified mount/read test.
  Previously released connectors show “status has not been reported” until updated.

The Windows mount is implemented in `integrations/windows-drive`. It ships as a
separate OPAL Drive installer with the upstream-signed Dokany driver, browser
account linking, user-scoped DPAPI credentials, bounded verified caching, private
Incoming staging and device status. See [Windows setup and operating limits](../integrations/windows-drive/README.md).
The Linux PrismFS/Samba service retains its existing shared drive principal.

There is also **no ingestion processor**. Submitted textures do not create a
material, generate maps, run a model, or publish anything.

## Account and transport contract

Start the existing device-code flow with `POST /api/v1/link` and
`{"client":"prismfs","machine":"DESIGN-01","app_version":"…"}`. Open the
returned verification URL in the user's browser and poll with the secret.
Approval and one-time credential collection each lock the device-link row. Drive
clients receive only `drive:read` and `drive:write` abilities. They cannot use the
general material administration API. Tokens remain revocable from Connect or
Developer access. Their permissions cannot exceed the user's current permissions.

Use the bearer token over HTTPS on the selected OPAL origin:

| Endpoint | Purpose |
| --- | --- |
| `GET /api/v1/drive/bootstrap` | Contract `opal-drive/1`, account, capabilities, limits, relative endpoint URLs, suggested mount and refresh periods |
| `GET /api/v1/drive/manifest` | Visible published files and the user's open Incoming folders; ETag/If-None-Match supported |
| `GET` / `HEAD /api/v1/drive/files/{derivative_uuid}/{file_id}` | Authorized immutable bytes, length, SHA-256 ETag and single `Range: bytes=…` support |
| `GET` / `HEAD /api/v1/drive/packages/{id}` | Authorized canonical USDZ at the same UUID/version path as the Omniverse resolver, with range support |
| `POST /api/v1/drive/heartbeat` | Device status, every 30 seconds |

The current workspace is resolved before checking local role permissions. Material
visibility uses the existing library/user/tenant grant rules; an intake batch is
private to its creator even when colleagues belong to the same workspace.

The manifest includes `path`, `bytes`, `sha256`, `content_url`, material/variant/
derivative UUIDs, material version, target, quality and role. Unlike other API
responses, the manifest is not wrapped in `data`. File URLs are relative to the
OPAL origin. Published files are read-only; writable Incoming folders are listed
separately. The `incoming` array is omitted from the user's view when write access
is unavailable (represented by an empty array).

Authorization happens before returning file bytes, HEAD metadata or a 304. Grant
revocation therefore applies to the next request, including direct file URL
requests. An already downloaded copy cannot be recalled. Responses use
`private, no-store`; ETags support explicit revalidation by the adapter. File
access decisions are recorded in `file_accesses` with channel `drive_https`.

`OpalDriveClient` uses the operating system's proxy and certificate trust, refuses
cross-origin file URLs and redirects, bounds each range read to 4 MiB, and checks
status, Content-Range, Content-Length and ETag before returning bytes. It rejects
truncated reads. Partial reads do not independently verify a full-file hash; the
Windows full-file cache verifies SHA-256 before committing an entry. Construct
a new client with the saved token after completing device linking.

The Windows adapter enforces the following transport requirements:

- Partition all metadata and cached bytes by server/account; clear the visible
  namespace on sign-out, 401 or 403. No offline authorization is implemented.
- Refresh the manifest approximately every 30 seconds, backing off on failures;
  recheck access before serving cached content. Never translate a transient
  network failure into a successful empty file.
- Pin open handles to an immutable manifest entry. New handles use refreshed
  entries. Never serve a different derivative through an existing handle.
- Fetch files on first read using bounded ranges, verify the complete SHA-256,
  then serve random reads from the cache. Eviction never removes an open file.
- Report `device_id` (persistent UUID per installation), `machine`, `version`,
  `state` (`connecting`, `mounted`, `error`, `offline`), `mount_path` and optional
  `error_code` (`network`, `sign_in`, `driver_missing`, `mount_in_use`,
  `access_denied`, `unknown`). “Mounted” requires a path. A heartbeat older than
  90 seconds, a revoked token or an expired token is shown as offline.

## Intake lifecycle

1. A contributor prepares a named batch on Connect or via
   `POST /api/v1/drive/intake` with `{"name":"Timber samples"}`.
   Its `/Incoming/{session_uuid}` entry appears only in that user's writable
   manifest. Sessions expire after 24 hours.
2. The adapter finishes writing a local staging file, calculates its byte length
   and SHA-256, then calls `POST /api/v1/drive/intake/{session}/files` with `path`,
   `bytes` and lowercase hex `sha256`. Paths are relative, Windows-compatible and
   case-insensitive for collision detection. A file cannot also be a directory.
3. `PUT /api/v1/drive/intake/{session}/files/{file_uuid}` sends raw bytes. The server
   spools to bounded temporary disk, checks size and SHA-256, then commits to the
   private intake disk. Only a committed upload sets `uploaded_at`.
   Repeating the same reservation/upload is idempotent. Different bytes at the
   same path are rejected. Retries resend the whole file; chunk-resumable upload,
   rename, readback and replacement are not implemented yet.
4. `GET /api/v1/drive/intake/{session}` reports each reserved file and its upload
   state. The list endpoint returns the newest 50 batches for the current user.
5. `POST /api/v1/drive/intake/{session}/submit` succeeds only when the batch is
   nonempty and every file is committed. It persists `status=submitted` and
   `submitted_at`, removes the writable folder and emits `IntakeSubmitted` after
   commit. Repeated submission is harmless. No processor is registered.
6. `DELETE /api/v1/drive/intake/{session}` closes an unsubmitted batch. Expiry and
   cancellation reject later writes. They retain staged files; there is no
   automatic deletion or retention policy in this change.

The future processor should consume the session UUID and load committed files
from `drive_intake_files`. Add an idempotent queue consumer/outbox and a sweep of
persisted submitted sessions before enabling processing. The in-process event
is a hook, not a durable queue; the submitted database rows are the recovery
source. Future material creation should record the session/file UUID as provenance
and apply explicit destination visibility and review rules. Do not publish a batch
merely because upload completed.

Defaults: 5 active batches per user, 500 files / 2 GiB per batch, 256 MiB per file.
`OPAL_INTAKE_DISK` defaults to `OPAL_FILES_DISK`; objects use a separate private
`opal/intake/…` prefix, never a published path. The server uses temporary disk up
to the per-file bound during validation. Submitted/expired storage is retained,
so production intake needs a retention policy and aggregate quota before broad
rollout. API requests are currently limited to 600/minute per authenticated user;
load-test and tune this for filesystem concurrency before a designer pilot.

## Release and verification

Apply migration `2026_09_23_010000_create_drive_intake_sessions.php` before deploying
the updated website. It creates three tables and adds nullable Revit session
status columns. Existing clients continue to work. Build the Revit connector to
ship its new folder status; updating the website alone does not update installed
connectors. No new storage credentials or public SMB exposure are required.

Validate HTTPS streaming/ranges through the production proxy, private staging on
the configured disk, upload body/time limits and temporary-disk capacity before
enabling a Windows uploader. Native client tests use a controlled HTTP handler;
they do not certify corporate proxy authentication or a Windows/Revit host.

Automated coverage is in `PersonalDriveTest`, `ConnectTest`, `DeviceLinkTest` and
`DriveTransportTests`: grants/revocation, publication checks, ranges, retries,
checksums, unsafe paths, quotas, expiry, ownership, scoped credentials, device
status and onboarding. The legacy `/files/{id}` browser route also enforces visible material
attachments and no-store responses, so a known file URL cannot bypass a grant
revocation. Publishers retain access to unattached files for administration.

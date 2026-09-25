# Drive upload queue

OPAL Drive contributors get `O:\upload` automatically. Copy files or nested folders there, then open **Ingestion** (`/ingestion`) to see received files, original paths, sizes, and incomplete transfers. Name the batch and choose **Queue for review** after Explorer finishes copying and all files are confirmed. The root upload folder then points to a new batch at the next drive refresh (normally 30 seconds). Closing an unfinished batch also rotates the root; received files and recoverable local payloads are retained.

This is collection and handoff only. No file classification, resolution inference, archive extraction, MDL execution, AI repair, material creation or publication runs. Source formats are unrestricted, subject to portable path and size limits. Nested file paths are retained; empty directories are not server records. Empty files are not uploaded by this client.

## Identity and access

- Each batch and file has a permanent UUIDv7. One non-expiring, active inbox exists per person and workspace. `POST /api/v1/drive/intake/inbox` creates or resumes it under a user-row lock; GET endpoints never create batches.
- The personal manifest retains `opal-drive/1`. New optional `upload_enabled` and `upload` fields advertise the root folder. Clients without this feature continue using legacy `/Incoming/{uuid}` sessions. The root is only advertised to contributors with `drive:write`; library files remain read-only.
- Unsubmitted and closed uploads are private to their owner in the current workspace. Explicit submission allows current workspace reviewers to inspect/download them. Legacy batches with no workspace remain owner-only even after submission. Storage keys and credentials are never exposed.
- Reads and Livewire actions recheck permission and workspace scope. Downloads force attachment/octet-stream/no-store; uploaded source content is not embedded or executed in the browser.
- New clients request an inbox only when the authenticated manifest advertises the feature. Submitting or closing a batch removes its write authority immediately on the server and within the drive refresh lease on clients.

## Durable transfer contract

Existing reserve → upload → submit endpoints remain the boundary. Reserve records the relative path, byte length and SHA-256. Upload streams into a bounded disk spool and verifies both length and checksum before private storage and acknowledgment. Repeating identical reservations/uploads is idempotent. Different content at an existing path and file/folder collisions are rejected.

The page polls while visible every 10 seconds and paginates batches/files. Progress measures **announced files received**, not a prediction of everything still being copied locally. A receiving batch with 100% of announced files can still accept more. The owner must finish the copy before submitting. A submitted batch emits `IntakeSubmitted` once after the database transaction commits; no processor is attached.

Windows stages locally and uploads only after the writer closes. Queue metadata and payloads survive restart. Keys include batch identity for `/upload`, preventing identical names in successive batches from sharing or replacing bytes. Existing Incoming queue records retain their old key scheme. A handle opened in a closed batch cannot become writable in the next batch.

Current limits: 256 MiB/file, 500 files and 2 GiB/batch; local staging capacity is 2 GiB total, including retained batches. Reserved files cannot be replaced, renamed or deleted in the mounted staging folder. Confirmed server files can be downloaded from the page, including on another computer; the drive does not mirror server staging back into Explorer. Keep original source files. Recover local copies from the account's `uploads` directory described in the Windows drive README.

## Mount scope

The shared Nucleus library and PrismFS/SMB projections remain read-only. They have service identity, not the personal OPAL identity required by the private inbox. Adding writable Nucleus intake needs a separately authorized source/bridge; making its shared library facade writable would not preserve personal upload permissions. This release provides upload on the signed-in Windows drive plus the common HTTPS queue API.

## Validation

Feature tests cover idempotent inbox creation, expiration behavior, token permissions, owner/workspace isolation, file download permissions, nested MDL preservation, incomplete submission, one-time events, legacy visibility and rotation. Portable drive tests cover recovery and same-name rotation. The Windows CI mount exercise now copies and reads a nested file through the real `/upload` filesystem callbacks before verifying upload acknowledgment.

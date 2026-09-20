# Bender material bridge · contract 1

Authenticated requests live under `/api/v1/bender`. Use a Sanctum PAT belonging to the initiating person with `bender:materials`, an expiry, and no `*` ability. Such a token cannot call other Opal API routes. Opal verifies current central Olsyn grants, a verified WorkOS identity, tenant access and local material permissions; super administrators still need central contribute permission to commission.

- `GET identity`: contract `bender-materials/1`, stable WorkOS `subject`, current `tenant_id`, display name and `can_commission`. Bender pins both subject and tenant when connecting.
- `GET materials?q=...&mode=keyword|semantic`: existing visibility-aware Opal catalogue search. Opal owns embedding generation and ranking.
- `POST commissions`: `idempotency_key` (100 characters), `brief` (3–8000), `bender_job_id` (UUID), `trace_id` (32 lowercase hex). Returns `id`, `decision` (`reuse` or `commission`), status and safe artifact metadata. Retries with changed inputs return 409. Opal reuses the same person's ready, accessible draft for the normalized brief; otherwise it authorizes one new private commission. New requests are limited to 30 per person/day, in addition to 60 requests/minute.
- `POST commissions/{id}/artifacts/{role}`: `data` (base64 PNG), `sha256`. Roles: `base_color`, `normal`, `roughness`. At most 4 MiB and 2048×2048 pixels per map. Identical retries succeed; conflicting content returns 409.
- `POST commissions/{id}/complete`: `width_mm` and `height_mm` (nullable when unknown), `normal_convention` (`opengl`), `source_job_id`, three `bender_artifact_ids`. Requires all maps at matching dimensions. Atomically creates a private Studio draft/revision and records Bender provenance. Repeating identical completion succeeds; changed metadata returns 409. No material, representation or published version is created automatically.
- `GET commissions/{id}`: status, checksums, draft URL and provenance.
- `GET commissions/{id}/artifacts/{role}`: authenticated ready PNG, no-store, nosniff. No bearer tokens in URLs. Cross-person or cross-tenant IDs return 404; discarded/deleted drafts return 410.

Commission records and draft assets belong to the same person and tenant. Disconnect/revoke the PAT to prevent further bridge access. Copies already delivered to authorized Bender jobs remain part of those jobs' provenance. Unknown map scale must be resolved before Opal's existing promotion action permits catalogue publication.

Tests: `php artisan test --compact tests/Feature/Library/BenderMaterialsTest.php`. This verifies identity, scope confinement, duplicate requests/uploads/completion, reuse, cross-user and tenant boundaries, and central permission revocation for super administrators.

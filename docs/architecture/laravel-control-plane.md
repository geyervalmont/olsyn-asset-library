# Laravel control-plane foundations

The application uses one PostgreSQL database with row-level tenant isolation.
`users` and `tenants` have a many-to-many membership relationship, and each user
stores the tenant currently selected in the UI. Tenant-aware routes use the
`tenant` middleware group after `auth`; it validates membership and establishes
the tenant context for the duration of the request.

## Invariants

- Tenant-owned Eloquent models use `App\Models\Concerns\BelongsToTenant` and
  have a non-null `tenant_id` foreign key. The concern fills the key on create
  and fails closed on reads when no current tenant exists.
- Spatie Permission runs with teams enabled and uses `tenant_id` as its team
  key. Changing the Spatie Multitenancy context also changes the permission
  team, including inside queued jobs.
- Spatie Media Library uses the tenant-scoped `App\Models\Media` model. Media
  objects default to the S3-compatible disk and live below the stable path
  `tenants/{tenant_id}/media/{media_uuid}`.
- Tenant context is persisted through Livewire update requests. Tenant-aware
  queues are enabled so conversions and other jobs restore the dispatching
  tenant automatically.
- A tenant cannot be deleted while media rows still exist. Application-level
  deletion must remove media through Eloquent first so Media Library can clean
  up the corresponding objects.

The dashboard and account settings are intentionally landlord routes for now.
Material routes should be placed behind `auth`, `verified`, and `tenant` once
the material domain is introduced. Tenant creation and onboarding UX are also
deferred until the product model is agreed.

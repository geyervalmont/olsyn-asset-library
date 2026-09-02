# Laravel control-plane foundations

The application uses one PostgreSQL database and one shared schema. There are
no per-tenant databases. Tenant isolation is row-level and enforced by the
application.

## Accounts, tenants, and membership

- A **user** is an account. Registration creates a plain user with no
  tenant membership and no elevated access.
- A **tenant** is an overarching company or workspace. A user may belong to
  zero, one, or many tenants through `tenant_user`, and holds exactly one role
  per tenant. Most users are expected to belong to a single tenant for now;
  the model exists so that client and contractor workspaces can be added later
  without a migration.
- Each user stores the tenant currently selected in the UI
  (`users.current_tenant_id`). It may be null.
- A **super-admin** (`users.is_super_admin`) bypasses every authorization
  check in every tenant. It is a user flag, not a role, so it does not depend
  on which tenant is current. It can only be granted from the console:
  `php artisan olsyn:super-admin user@example.com` (`--revoke` to remove).

## Roles and permissions

Spatie Permission runs with teams enabled and `tenant_id` as the team key.

- **Permissions** (`App\Enums\Permission`) are defined once, globally.
- **Roles** (`App\Enums\Role`: `admin`, `editor`, `viewer`) are also defined
  globally (`roles.tenant_id IS NULL`) so Spatie resolves them in every tenant
  context. What is tenant-specific is the *assignment*: `model_has_roles`
  records the tenant, so a user can be an admin in one tenant and a viewer in
  another.
- `App\Actions\Authorization\SyncRolesAndPermissions` creates or updates the
  definitions idempotently. The database seeder calls it; deployments can run
  `php artisan db:seed --class=RolesAndPermissionsSeeder`.
- `Gate::before` grants everything to super-admins. Spatie's own gate hook
  handles `$user->can('materials.view')` for regular members inside the
  current tenant.

Membership changes go through actions so that the pivot row, the tenant-scoped
role, and the user's current tenant stay consistent:
`App\Actions\Tenants\CreateTenant`, `AddTenantMember`, `RemoveTenantMember`,
and `SwitchCurrentTenant`.

## Request lifecycle

Routes that need a tenant use the `tenant` middleware group after `auth`. It
resolves the user's current tenant, makes it current (which also switches the
permission team), and forgets it after the response. Its rules are:

- a current tenant the user may still access is used as-is;
- a stale or unauthorized selection is replaced by the user's first
  membership;
- a user with no membership is redirected to the dashboard with a status
  message (JSON requests receive a 403 instead);
- super-admins may select any tenant, member or not.

The dashboard, account settings, and tenant switching are landlord routes:
they only need `auth` and `verified`. The dashboard shows the current
workspace, the user's memberships, and, for super-admins, every workspace.

## Invariants

- Tenant-owned Eloquent models use `App\Models\Concerns\BelongsToTenant` and
  have a non-null `tenant_id` foreign key. The concern fills the key on create
  and fails closed on reads when no current tenant exists. Super-admin status
  does not relax this: data is always read inside one tenant.
- Changing the Spatie Multitenancy context also changes the permission team,
  including inside queued jobs. Code that switches tenants must unset the
  cached `roles` and `permissions` relations on any loaded user.
- Spatie Media Library uses the tenant-scoped `App\Models\Media` model. Media
  objects default to the S3-compatible disk and live below the stable path
  `tenants/{tenant_id}/media/{media_uuid}`.
- Tenant context is persisted through Livewire update requests. Tenant-aware
  queues are enabled so conversions and other jobs restore the dispatching
  tenant automatically.
- A tenant cannot be deleted while media rows still exist. Application-level
  deletion must remove media through Eloquent first so Media Library can clean
  up the corresponding objects.
- Email addresses must be verified (`MustVerifyEmail`) before the dashboard
  or any `verified` route is available. Locally, Mailpit receives the mail.

## Development accounts

`php artisan migrate --seed` (run by `make bootstrap`) is idempotent and
creates the `Olsyn` tenant plus these accounts, all with password `password`:

| Email | Access |
| --- | --- |
| `admin@example.com` | super-admin; admin of Olsyn |
| `test@example.com` | editor in Olsyn |
| `viewer@example.com` | viewer in Olsyn |
| `solo@example.com` | no workspace membership |

Material routes should be placed behind `auth`, `verified`, and `tenant` once
the material domain is introduced. Tenant creation and invitation UX are
deferred until the product model is agreed; for now tenants and memberships
are created through the seeder, the actions above, or Tinker.

# Olsyn service authentication — reference integrations

Render Farm (FastAPI + React) and Opal (Laravel + Livewire) are the reference implementations for future Olsyn services.

## Contract

WorkOS Connect provides OIDC authentication at `https://auth.olsyn.com`. Every service has its own first-party Connect application, client ID and exact callback URI. All use the same existing WorkOS environment and user IDs. IT can add Entra upstream later without changing service permissions. AWS federation is separate.

WorkOS currently rejects client-secret creation when `uses_pkce=true`. These integrations therefore use mandatory PKCE applications with authorization code + S256, unpredictable state and nonce, and server-side code exchange. No WorkOS environment API key is distributed to the services. Client IDs are public. The code verifier stays in the application's protected session.

Authentication never grants service access. The main site's **People & access → Service access** owns explicit grants in the central database. A platform administrator still needs an explicit service grant. Only local platform administrators/owners can change grants; WorkOS organization roles do not confer this power. Every change records the actor, target, previous/new permissions and reason in `service_access_events`.

The backend calls `POST https://app.olsyn.com/api/service-access/v1/{service}/decision` with its own random bearer credential and either `workos_id` or an independently authenticated legacy `email`. The URL binds the credential to one service. A service must never accept an identity asserted by a browser header or query parameter. The response supplies `allowed`, `service`, `permissions`, local `subject`, `email`, `name` and `max_age: 15`. Unknown identities receive a denial. Register a new person by having them sign in to the main Olsyn site first.

Clients validate the response's service and shape, cache at most 15 seconds, and fail closed when a fresh decision is unavailable. Revocation, permission changes and expiry affect subsequent requests within 15 seconds, including existing browser sessions and Opal personal API tokens. Existing downloads, pre-signed storage URLs, open realtime connections and already running jobs are not recalled. No background job is cancelled by removing its submitter's access.

## Reference implementations

| Concern | Render Farm | Opal |
|---|---|---|
| OIDC | `app/oidc.py`, Authlib | `app/Services/OlsynOidc.php`, Firebase JWT |
| Policy client | `app/service_access.py` | `app/Services/OlsynAccess.php` |
| Enforcement | Dependencies on every human API router | Web/Livewire/API middleware plus permission checks |
| Permissions | `farm.view`, `jobs.submit`, `farm.manage` | View/contribute/review/publish materials; manage members/settings |
| Recovery identity | Existing signed Cloudflare Access identity | Existing local password/passkey account |
| Machine identity | Existing internal JWT; human relays also check actor grants | Sanctum tokens inherit their user's current service grant |
| Background protocol | Worker callbacks remain cluster-only; rejected through public proxy | PrismFS drive credentials remain a separate machine authority |

Render artists may submit/control jobs; changing queue priority, cloud placement, GPU capacity, Nucleus servers or deleting stored output requires farm administration. New unclassified mutations require administration by default. Reads require `farm.view`.

Opal's central permissions are a ceiling, intersected with existing workspace membership and roles. An Opal grant cannot expose another customer's workspace. Even a local super-admin cannot bypass a central permission denial. Workspace membership continues to be managed in Opal. A new SSO identity is never automatically linked to an existing account by email; the administrator runs `php artisan opal:link-workos EMAIL WORKOS_ID`, which verifies that the central grant matches the existing account. Passwords, passkeys, MFA and roles survive linking.

## Add another service

1. Define its service slug, display name, entry permission, meaningful permissions and role presets in `config/service_access.php` in the main website. Code-review this catalog rather than allowing arbitrary endpoints or permissions from the browser.
2. Register a first-party WorkOS Connect OAuth application in the **same environment**, with a fixed HTTPS callback and mandatory PKCE. Never reuse another service's client ID.
3. Create a random service policy credential of at least 32 bytes. Store it in the main website's secret and that service's secret only. A compromised service must not be able to query another service's decisions. Rotate by coordinating both ends; never publish credentials in images, JavaScript, Git, logs or this guide.
4. Adapt one reference client. Validate signature, issuer, audience, expiry, nonce and verified email. Consume state once. Use secure HttpOnly host-only session cookies, fixed redirects, absolute session expiry and CSRF protection for cookie-authenticated mutations.
5. Enforce the service entry grant on every protected route, API, file endpoint and Livewire/action request. Check operation permissions server-side. Preserve tenant/resource checks. Inventory worker callbacks, webhooks, API keys and long-lived connections explicitly.
6. Grant the owner access before enabling enforcement. Verify allowed, ungranted and revoked users; wrong-service credentials; wrong-audience tokens; callback replay; expiry; CSRF; a policy outage; and independent recovery.
7. Build an immutable image, migrate additively, deploy to a scoped canary/pilot and test the real callback before changing the default login. Capture the previous image/config and retain a rollback path.

## Deployment and recovery

Main website requires `SERVICE_ACCESS_RENDER_TOKEN` and `SERVICE_ACCESS_OPAL_TOKEN`, sourced from the `olsyn-service-access` Kubernetes secret. Its migration is `2026_09_07_100000_create_service_access_tables.php` and runs only on the central database. The infra production overlay contains the durable secret references.

Render's `olsyn-access` secret holds `RENDER_ACCESS_TOKEN` and `RENDER_SESSION_SECRET`; its deployment pins the client ID and `RENDER_OLSYN_ACCESS_ENABLED=true`. Main's local grants remain authoritative for the existing Cloudflare identity too. `/auth/recovery` clears an expired WorkOS session and uses Cloudflare's existing identity or team launcher. Workers keep their current protocol, so no running GPU jobs need replacement.

Opal's `olsyn-access` secret holds `OLSYN_ACCESS_TOKEN`. Non-secret OIDC values and `OLSYN_ACCESS_ENABLED=true` are in its ConfigMap. All web/auth-capable workloads read the same config. The additive migration adds a unique nullable `users.workos_id` field. Existing password/passkey login remains at `/login` alongside Continue with Olsyn. Restricting service access also restricts these local sessions; recovery authentication does not override a deliberate grant revocation.

WorkOS outage: already authenticated sessions last until their configured absolute expiry; use independent service recovery authentication when necessary. Policy checks themselves do not call WorkOS. Main-site **new login still depends on WorkOS**; this change does not add an independent main-site login provider. AWS IAM, VPN, SSM and SSH administrative recovery remain separate. If the main policy service is unavailable, services deny after the short decision cache expires. Recover that service through the existing infrastructure paths; never replace failed authorization with allow-all.

Rollback: restore the recorded previous service image and corresponding config; retain additive database columns/tables and grants. Do not drop grant history or rotate signing/session secrets as part of a routine rollback. Do not disable authentication to diagnose an outage.

## Sources

- [WorkOS Connect OAuth](https://workos.com/docs/authkit/connect/oauth)
- [WorkOS application API](https://workos.com/docs/reference/workos-connect/applications)
- [Authlib Starlette OIDC](https://docs.authlib.org/en/latest/client/starlette.html)
- [Cloudflare Access CLI recovery](https://developers.cloudflare.com/cloudflare-one/tutorials/cli/)

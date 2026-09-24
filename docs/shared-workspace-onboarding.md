# Shared workspace and team onboarding

OPAL now defaults to one shared workspace. The sidebar names the shared library;
it does not offer a workspace switcher or a Workspaces destination. Existing
members land directly in the material library. Administrators have a **Team**
page at `/team` for invitations, role changes and removing access.

## Onboarding a designer

1. Approve the person's OPAL access in Olsyn, using their usual identity.
2. Share `/join` on the OPAL site. For central sign-in, this goes directly to
   Olsyn and returns to the connector setup page after authentication.
3. The verified account joins the configured shared workspace automatically.
   Central `materials.contribute` access gives the local Designer (Editor) role;
   otherwise the account receives Viewer. Auto-enrollment never grants Admin.
4. If a particular local role is needed, prepare an invitation by email on Team
   before the person signs in, or change their role after they join.

The normal login screen prioritises Olsyn. Existing OPAL passwords and passkeys
remain available in an expandable section. The single-workspace change does not
link unrelated external identities to existing accounts by matching email;
OIDC's existing identity-linking safeguard is unchanged.

Invitations queue an onboarding email and show queued/sent/failed status on Team.
They do not grant access in the Olsyn identity service. A pending invitation applies
only to its verified email, expires after seven days, and is consumed on sign-in.
The shared sign-in link also remains available for manual sharing.

## Membership and roles

The existing tenant memberships and role tables remain authoritative. A new
`workspace_access` table records pending email invitations, acceptance, role
updates and removals, including the last person who changed the entry. It is a
current-state ledger, not an append-only audit log. Existing roles are not reset
on every sign-in. Central permissions remain an upper bound on local roles.

All Team mutations require `members.manage` in the target workspace. They lock
the workspace row to serialize invitation acceptance, role changes and removal.
The last workspace administrator cannot be removed or demoted through Team.
Global administrators cannot be edited there. Other workspaces' members cannot be
modified by supplying their user IDs.

Removal persists an enrollment block by email and user ID, including removal
through the existing `RemoveTenantMember` action. Automatic enrollment will not
restore a removed member. An explicit fresh invitation or administrative member
addition can restore access. Cancelling a pending invitation also blocks automatic
enrollment for that email until a fresh invitation is issued. Removing membership
does not delete the account or other workspace memberships. Authorization on the
next request rejects workspace/drive access; previously downloaded files cannot
be recalled.

## Configuration and deployment

Apply `2026_09_23_020000_create_workspace_access.php` before deploying this code.

| Variable | Default | Effect |
| --- | --- | --- |
| `OPAL_SINGLE_WORKSPACE` | `true` | Shared workspace UI and automatic selection |
| `OPAL_WORKSPACE_SLUG` | `olsyn` | Explicit shared workspace to use |
| `OPAL_WORKSPACE_AUTO_JOIN` | `true` | Enroll verified, centrally approved OPAL users |

Auto-enrollment requires `OLSYN_ACCESS_ENABLED=true` and an affirmative central
access decision. With central access disabled, membership requires an existing
membership or an explicit invitation. Set `OPAL_WORKSPACE_AUTO_JOIN=false` for
invitation-only onboarding even when central access is enabled.

Set the workspace slug explicitly in production. A missing configured slug does
not select an arbitrary workspace or create one silently. When the slug is empty,
OPAL can infer the workspace only if exactly one exists. Confirm the intended
workspace and an existing administrator before rollout. There is no membership
merge or deletion migration. The former multi-workspace UI and selection behavior
remain available with `OPAL_SINGLE_WORKSPACE=false`; legacy tenancy tests exercise
that mode, while `SharedWorkspaceTest` explicitly enables the shared mode.

Browser checks cover preparing an invitation, signing in as the invited designer,
landing on Connect with the correct role, the simplified sidebar, mobile layout,
and removal taking effect in an already-open browser session. Automated tests
cover central approval/denial, verified email matching, expiry, cancellation,
re-enrollment blocks, role boundaries, last-admin protection, workspace isolation,
and shared-workspace onboarding for the personal-drive API.

## Email delivery

Invitations now queue an onboarding email and show delivery state in Team. Administrators can resend after one minute. See [consumer extension setup](consumer-extension-poc.md) for the current email, release and client workflow.

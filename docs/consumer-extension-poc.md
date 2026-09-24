# Consumer extension proof of concept

The existing repository is a monorepo: `apps/web` owns the API/site, `integrations/revit-native` owns the Revit consumer, `integrations/omniverse` owns the Kit consumer, and `services/prismfs` owns the shared drive. Components keep independent build entry points and release workflows.

## Daily iteration

- Revit changes run `.github/workflows/revit-extension.yml` and produce installers/packages for 2024–2027. Push `revit/v0.2.0` (then increment semantic version) to publish. PR/main builds are downloadable workflow artifacts; they do not change the installed production version.
- Omniverse changes run `.github/workflows/omniverse-extension.yml`. Push `omniverse/v0.1.0` (then increment) to publish a portable Kit extension ZIP. This workflow also tests real USD material bindings with `usd-core`.
- Both workflows can be started manually to build artifacts. Only a version tag publishes an immutable version. Stable aliases remain for existing updater clients; an older tag cannot roll the alias back.
- Connect and `GET /api/v1/client-releases` discover published tagged versions from GitHub, cached for two minutes. No website deployment is needed to list another extension release.
- Revit packaging uses GitHub Windows because it runs .NET Framework tests and Inno Setup. For an organization with Blacksmith installed, set repository variable `OPAL_LINUX_RUNNER` to its provisioned Linux runner label; otherwise Omniverse uses `ubuntu-latest`. This repository does not require a new Blacksmith subscription.

## Onboarding

An administrator opens Team, enters an email and role, and sends the invitation. A queued job sends the setup email after the access transaction commits. Team displays queued/sent/failed and supports resending with a one-minute limit. Cancelled, expired and superseded invitations are checked before sending. Sending a message does not bypass central Olsyn service access. Invitations expire after seven days.

`php artisan opal:onboarding:test recipient@example.com` sends a clearly labelled test of the same template without granting access. A successful mail submission confirms transport acceptance; inbox delivery requires checking the receiving mailbox.

Use Connect for extension downloads and installation instructions. Both extensions start `/api/v1/link`, open the browser approval page and receive the bearer token exactly once. Omniverse reports its session and drive state to Connect. Disconnect revokes that device's token. Windows Omniverse tokens use user-scoped DPAPI; Linux sessions do not persist tokens.

## Consumer API

All library endpoints require authentication, central service access, a workspace and `materials.view`. Existing material visibility grants apply before returning previews, resolution results or files.

| Endpoint | Purpose |
| --- | --- |
| `GET /api/v1/library?q=&category=&page=&per_page=` | Published variant summaries, at most 50 per page; pagination metadata, UUIDs and preview URL. No embedded texture payloads. |
| `GET /api/v1/library/facets` | Categories visible to the caller. |
| `GET /api/v1/library/variants/{uuid}/preview` | Smallest published base-colour preview. |
| `GET /api/v1/library/variants/{uuid}/resolve?target=revit&version=1` | The published 512 px preview, or 409 if unavailable. Never silently choose a larger Revit tier. |
| `GET /api/v1/library/variants/{uuid}/resolve?target=omniverse&version=1` | Highest PBR projection, or the original canonical USDZ with all available tiers. |
| `GET /api/v1/library/packages/{id}` | Authenticated published canonical bytes; user visibility checked again. |
| `GET /api/v1/drive/files/{derivative}/{file}` | Existing SHA-addressed published derivative bytes, including HTTP ranges. |
| `DELETE /api/v1/account/token` | Revoke the calling personal access token. |

Consumer pages eager-load their parent/category/supplier/version in a bounded number of queries. A resolution loads one material/version rather than the entire drive manifest. Heavy synthesis/export work is never performed by a browsing request. Each resolved file includes immutable path, byte count and SHA-256; private storage credentials never reach clients.

## Material identity and paths

`material_uuid + variant_uuid + material_version + source_package_sha256` identify the material used by a document. Revit writes this to Extensible Storage and exports a portable JSON sidecar. Codes remain searchable labels, not the cross-application identity.

Revit applies a 512 px representation. Omniverse resolves the same published version to high-resolution textures; it does not silently upgrade to a newer artistic revision. If only the canonical USDZ exists, the Kit extension reads its neutral manifest, selects the highest available texture tier for each input, and authors a UsdPreviewSurface network. Texture channel/colour space, scalar constants, modulation and DirectX normal orientation are retained for the supported PBR inputs.

Both clients prefer an existing material mount. If it is unavailable, they download only selected material content over normal HTTPS into a per-user cache. Downloads are bounded, written atomically and verified before use. Cache paths include UUIDs and version; they do not change when material names change. Cached content is not retroactively erased by account revocation. Install the separate [OPAL Drive Windows client](../integrations/windows-drive/README.md) to mount these paths over HTTPS. Both extensions discover its same-account mount automatically. For portable scenes, use a common drive path or package the cached assets with the scene.

## Host proof of concept

1. Install the Revit release, connect, open a project, and choose **Browse Library**. Browse all pages, preview, import, then assign the material in Revit. **Apply Material** targets an existing selected material/face.
2. Export USD and **Export Material IDs** alongside it.
3. Enable the Omniverse extension from its extracted `exts` directory, connect, open the exported USD, enter the mapping path and choose **Upgrade stage materials**.
4. Confirm material identity, high-resolution texture paths, physical repeat and orientation in your actual exporter, then save the stage.

USD tests cover preservation of binding targets, face subsets and UV primvars. Actual Revit-host behavior, Kit UI compatibility and exporter-specific names/metadata must also be tested on the designer's Windows/Kit installation. Unknown or ambiguous material names are never guessed. The POC supports basic PBR shading, not perfect equivalence for every Revit appearance asset, MDL network or advanced OpenPBR lobe.

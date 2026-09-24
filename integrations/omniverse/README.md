# OPAL for Omniverse (proof of concept)

Extract the release ZIP. In Kit, open Window → Extensions → Settings, add the extracted `exts` directory to Extension Search Paths, then enable **OPAL Materials**. Requires a Kit host with `omni.ui`, `omni.usd` and Python 3.10+.

Choose **Connect account**, approve the browser code using your Olsyn account, then search published materials. Select scene geometry and choose **Apply to selected prims**. Downloads run off the Kit main thread; USD edits run on it. Windows credentials are encrypted with user-scoped DPAPI. Linux credentials are session-only. Standard OS HTTPS/proxy/certificate settings apply; no VPN is needed for texture downloads.

An available material drive is preferred. Otherwise the extension downloads selected maps into its per-user cache, checks SHA-256 and byte counts, and uses stable UUID/version paths. This cache is not a mounted virtual drive. Cached files remain available offline; deleting or revoking access does not delete previously downloaded files. Share the mounted material root or package the cache alongside a USD scene before sending that scene to another machine.

## Revit → USD upgrade

1. Use the new Revit extension to apply a published material. It records material/variant UUIDs, published version, and source package hash in Revit Extensible Storage; Revit textures are 512 px.
2. Export USD using your normal exporter. If it does not retain OPAL custom data, also choose **OPAL → Export Material IDs** in Revit.
3. Open the USD stage, enter the material-map file path if needed, then choose **Upgrade stage materials**. UUID metadata is preferred. The sidecar matches only unambiguous exact material names (including USD-safe names).
4. The extension resolves that same published version to the highest published PBR quality, using the original canonical USDZ when separate PBR maps have not been generated, verifies source identity and texture bytes, creates UsdPreviewSurface materials and replaces existing binding targets. Face subsets and UVs are retained. Save the stage explicitly.

Exporter-specific renaming can prevent a sidecar match. Missing or ambiguous identities are not guessed. This is a PBR preview-surface proof of concept, not an exact conversion of every Revit appearance shader or an MDL authoring system. Validate UV scale and orientation in your actual exporter. The Kit UI and actual Revit-to-USD exporter must be smoke-tested in their host applications; CI exercises transport, packaging and USD binding behavior.

## Build

`python -m unittest discover -s integrations/omniverse/tests -v` (install `usd-core==25.11` for USD tests).

`python integrations/omniverse/build.py`

Push `omniverse/vMAJOR.MINOR.PATCH` to publish independently. Main/PR changes build artifacts without publishing a production version.

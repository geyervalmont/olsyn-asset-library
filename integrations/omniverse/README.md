# OPAL for Omniverse (proof of concept)

Extract the release ZIP. In Kit, open Window → Extensions → Settings, add the extracted `exts` directory to Extension Search Paths, then enable **OPAL Materials**. Requires a Kit host with `omni.ui`, `omni.usd`, `omni.kit.menu.utils` and Python 3.10+.

Choose **Connect account**, approve the browser code using your Olsyn account, then search published materials. Select scene geometry and choose **Apply to selection**. Downloads run off the Kit main thread; USD edits run on it. Windows credentials are encrypted with user-scoped DPAPI. Linux credentials are session-only. Standard OS HTTPS/proxy/certificate settings apply; no VPN is needed for texture downloads.

The browser shows 24 preview cards per page, category filters, search, numbered pages, and a selected-material inspector. Thumbnails load four at a time and are cached by variant UUID and published version. Search or page changes discard obsolete preview updates. Connection, loading, empty results and apply outcomes are shown in the panel.

With extension 0.2.0 or later, you can also apply from the OPAL website. Select geometry in Kit, then choose **Apply in Omniverse** on a material or Studio draft. When several applications or documents are connected, choose the destination first. Commands travel over HTTPS, and the website receives their processing, success or failure status. Temporary Studio drafts are marked separately from published material identities. Result delivery is retried without reapplying during the same Kit session; pending work interrupted by closing Kit may need to be sent again.

An available material drive is preferred. Otherwise the extension downloads selected maps into its per-user cache, checks SHA-256 and byte counts, and uses stable UUID/version paths. This cache is not a mounted virtual drive. Cached files remain available offline; deleting or revoking access does not delete previously downloaded files. Share the mounted material root or package the cache alongside a USD scene before sending that scene to another machine.

## Revit → USD upgrade

1. Use the new Revit extension to apply a published material. It records material/variant UUIDs, published version, and source package hash in Revit Extensible Storage; Revit textures are 512 px.
2. Export USD using your normal exporter. If it does not retain OPAL custom data, also choose **OPAL → Export Material IDs** in Revit.
3. Open the USD stage, enter the material-map file path if needed, then choose **Upgrade stage materials**. UUID metadata is preferred. The sidecar matches only unambiguous exact material names (including USD-safe names).
4. The extension resolves that same published version to the highest published PBR quality, using the original canonical USDZ when separate PBR maps have not been generated, verifies source identity and texture bytes, creates UsdPreviewSurface materials and replaces existing binding targets. Face subsets and UVs are retained. Save the stage explicitly.

Exporter-specific renaming can prevent a sidecar match. Missing or ambiguous identities are not guessed. This is a PBR preview-surface proof of concept, not an exact conversion of every Revit appearance shader or an MDL authoring system. Validate UV scale and orientation in your actual exporter. The Kit UI and actual Revit-to-USD exporter must be smoke-tested in their host applications; CI exercises transport, packaging and USD binding behavior.

For a Linux Kit host, add `--ext-folder /path/to/extracted/exts --enable olsyn.opal` to its launcher. On laptops with multiple GPUs, ensure the host process can see the NVIDIA Vulkan driver; an environment pinned to another GPU prevents RTX rendering. No desktop-wide configuration change is needed.

## Build

`python -m unittest discover -s integrations/omniverse/tests -v` (install `usd-core==25.11` for USD tests).

`python integrations/omniverse/build.py`

Push `omniverse/vMAJOR.MINOR.PATCH` to publish independently. Main/PR changes build artifacts without publishing a production version.

## Browser (0.2.1)

The panel uses Kit's native controls and can be docked or reopened with **Window → OPAL Materials**. Search updates as you type; category filtering stays visible and **Reset filters** returns to the whole library. Click either a thumbnail or its label to select a material. **Refresh** keeps the current page and selected material when it remains in the results. Failed searches show a retry state instead of leaving stale materials active.

Run the mouse/keyboard regression in a **disposable, signed-out Kit host** (it closes the host after testing):

```sh
OPAL_UI_TEST_OUTPUT=/tmp/opal-ui-tests "$KIT_ROOT/kit" "$KIT_ROOT/apps/omni.app.mini.kit" \
  --ext-folder "$PWD/integrations/omniverse/exts" --enable olsyn.opal \
  --/app/settings/persistent=false \
  --exec "$PWD/integrations/omniverse/tests/kit/browser_interactions.py"
```

This uses local coloured fixtures, exercises selection, typed search, filters, pagination, refresh, request races, empty/error states and window reopening, and writes a screenshot and `result.json`. It needs a graphics-capable Kit host; the portable client/USD tests continue to run in GitHub Actions.

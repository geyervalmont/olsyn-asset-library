# Material library browsing

The library index renders 24 materials per page. Cards request only the selected
colourway's 512 px WebP render; other colourways use their dominant colours until
hovered or selected. List view uses 96 px previews. Do not add render URLs as CSS
backgrounds on colourway buttons: browsers download them even when hidden.

`files.preview` checks the same material visibility rules as original downloads.
Its private browser cache must revalidate authorization before either a 200 or
304 response. Resized bytes are cached privately on each application server for
30 days, keyed by immutable file hash, size and conversion version. Original
files, published packages and drive paths are unchanged. Successful preview
transfers are recorded in `file_accesses` with their actual transferred size.

Clicking a card or list row opens the dialog locally on the next frame, using
the title and swatch already on the page. Its isolated Livewire component loads
the material details without rendering or querying the library grid. 3D starts
automatically once those details arrive; no second click is required. Nothing
downloads a 3D renderer or material graph just to display the library list.

The quick view loads one approved (or candidate) canonical representation per
colourway rather than every target, resolution and historical representation.
Fallback maps use authenticated 1024 px previews. MaterialX downloads overlap
renderer/lighting initialization. Colourway hover remains a lightweight swatch
preview; selecting a colourway changes the 3D material. Connected-app polling
updates only the dialog and preserves the renderer and grid.

Large source maps (up to 64 megapixels, within the existing 20 MiB input limit)
use ImageMagick's bounded memory/disk cache for resizing; small images retain
the GD path. This lets an 8192×4096 normal map supply a small browser preview
without downloading the original or failing the old 16-megapixel limit.
Temporary image resource limits are restored after conversion.

Close and Escape dismiss immediately, restore focus and cancel an active graph
download. A request number prevents late replies from reopening a closed dialog
or replacing a newer selection. Deep links (`?material=CODE`), modified clicks
to the full record, consumer selection and server-side authorization remain.
Other material inspectors start when scrolled into view.

## Quick-view measurements, 25 September 2026

Local Chromium, the Spinifex material and cached server-side MaterialX conversion:

- Before: approximately 831 ms from the browser test's click operation to the
  dialog appearing, then a separate “Explore in 3D” click; 1637 ms total to ready.
- After: 55 ms including browser automation overhead; the dialog was visible on
  the first animation frame, 5.7 ms after the actual click event. Automatic 3D
  was ready at 1015 ms. A separate run reached ready in 892 ms.
- With the metadata request deliberately delayed by 2000 ms, the shell still
  appeared on the first frame (2 ms). Closing during that delay prevented the
  late response from opening or starting a renderer.
- The isolated open response was approximately 12.7 KB for this material.
  Initial application JS remains approximately 84 KB uncompressed; renderer
  chunks stay lazy.

These are local interaction measurements, not production latency promises. A
cold package conversion, network transfer and GPU compilation still take time;
the dialog shows the existing swatch while they finish. Browser checks cover
grid/list opening, deep links, keyboard focus, 390 px mobile layout, stale
responses and preservation of the canvas during connection-status updates.

## Measurements, 24 September 2026

- Initial application JS: approximately 608 KB before, 81 KB after (uncompressed
  build assets). The approximately 543 KB 3D chunk is loaded separately.
- Production's old first page referenced 120 preview images totalling 25.03 MiB,
  including the eagerly loaded colourway backgrounds. The new 24-material page
  has 22 selected renders totalling 402.4 KiB after conversion. This is the full
  page image budget; offscreen images remain lazy-loaded.
- The original production page query and preview metadata took 117 ms across
  12 queries. Image traffic and browser work were the primary targets.
- In the local browser, initial font requests fell from 19 to 5 by removing
  blanket preloads of unused families, weights and formats.

Image measurements use actual production files with the new conversion settings,
without changing source files. They are payload measurements, not a production
time-to-interactive benchmark. The local catalogue has fewer preview images than
production, so its page timings are not used to claim a production speedup.

Validation covers preview dimensions/cache reuse, authorization after visibility
changes, stale async renderer completion, library search and filtering, grid/list
switching, colourway selection, quick view, automatic 3D and mobile navigation.

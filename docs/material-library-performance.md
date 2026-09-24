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

Quick view starts with a 2D preview. “Explore in 3D” imports the renderer and loads
maps only on request. Other material inspectors start when scrolled into view.
Closing a preview while its renderer is loading cancels attachment to that host.

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
switching, colourway selection, quick view, opt-in 3D and mobile navigation.

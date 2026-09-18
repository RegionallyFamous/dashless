# World-class Astro frontend standard

This repository treats frontend quality as a release contract, not a visual
review performed after the fact.

## Source of truth

`templates/astro` is the only frontend. Examples may provide content fixtures,
snapshots, media, and configuration, but they must not contain a second
`src/`, route tree, layout system, or publication audit.

## Required gates

Every template or theme change must pass:

1. `astro check` with zero diagnostics.
2. A production static build.
3. The publication audit for routes, metadata, links, assets, feeds, and
   social cards.
4. The frontend quality budget in
   `templates/astro/scripts/check-frontend-quality.mjs`.
5. Browser coverage at 390, 768, 1280, and 320 pixels.
6. Keyboard navigation, no-JavaScript rendering, reduced motion, forced
   colors, theme persistence, search, missing media, and route transitions.
7. A fresh builder artifact and a live WP Cloud verification with its release
   identifier and DOM fingerprint.

## Performance budgets

The default static-output budgets are:

- JavaScript: 200 KB total.
- CSS: 180 KB total.
- HTML: 300 KB per page.
- Individual raster/vector asset: 500 KB.

Override a limit only for a measured reason and document the exception in the
change. Do not solve a budget failure by moving unprocessed CSS or JavaScript
into `public/`.

## Design fidelity

Imagegen boards are references, not production backgrounds. A theme is ready
only when its structure, geometry, typography, responsive reflow, interaction
states, and content states are represented in shared semantic Astro markup and
verified with deterministic screenshots. Palette-only changes do not qualify
as redesigns.

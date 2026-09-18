# Dashless theme system

This is the operating model for designing, building, reviewing, and publishing
themes. A theme is a complete reader experience, not a palette preset.

## Source of truth

`templates/astro` is the only publication frontend. WordPress supplies content
and media; it does not get a second production presentation tree. The theme
registry in `templates/astro/src/lib/themes.json` is the contract shared by
the Astro template, Hub catalog, previews, and MCP discovery.

Every theme manifest must include:

- a stable id and version;
- a visual reference under `docs/design/theme-boards/`;
- the seven required route states: home, archive, article, search, taxonomy,
  RSS, and 404;
- structural decisions for the header, hero, cards, reading column, and
  mobile transformation;
- defaults and bounded customization options.

Run `node scripts/check-theme-system.mjs` before changing implementation. It
fails when a board is missing, a route state is omitted, a structural decision
is empty, or two themes have the same structural acceptance signature.

## Build loop

1. Art-direct the theme with page-specific references for every required route
   state at 1440×1024 and 390×844.
2. Record the geometry and visual tokens beside the board. Geometry comes
   first: header height, content width, columns, reading measure, image ratios,
   spacing, and mobile reflow.
3. Implement the design with shared Astro components and theme variants. A
   new theme may add a named template only when the shared semantic structure
   cannot express its approved composition.
4. Exercise representative local fixtures: long and short titles, missing
   media, multiple terms, long articles, rich content, empty search, and 404.
5. Capture real output at both reference sizes. Review structure first,
   typography second, and surface treatment third. A color-only pass is not a
   redesign.
6. Run keyboard, narrow reflow, 200% zoom, dark mode, reduced motion, forced
   colors, no-JavaScript, and missing-media checks.

## Release gates

`local` requires the theme contract, Astro build, route audit, fixture QA, and
reviewed screenshot comparisons. `builder` requires the same evidence from
the Railway artifact. `live` requires a WP Cloud release header, DOM
fingerprint, selected-theme proof, and matching artifact manifest.

Activation is atomic: upload the immutable release, switch `current.json`,
verify the live fingerprint, remove manifest-listed superseded release files,
and remove stale root assets. If any pointer, manifest, asset, or fingerprint
check is ambiguous, stop without declaring success.

## Definition of done

A theme is complete only when its board, manifest, shared implementation,
route matrix, fixture screenshots, accessibility checks, builder artifact, and
live release evidence agree. “The page loaded” or “the CSS changed” is not
evidence of completion.

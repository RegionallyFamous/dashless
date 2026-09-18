---
name: design-dashless-astro-sites
description: Design, build, restyle, audit, or polish distinctive Astro publication sites backed by WordPress and Dashless. Use for Astro theme direction, Imagegen-led page comps, homepage and article layouts, content mapping, responsive behavior, day/night themes, accessible interactions, missing-media resilience, SEO and social metadata, populated local previews, visual QA, or best-practices reviews of a Dashless-generated frontend or its default template.
---

# Design Dashless Astro Sites

Build a memorable reader-facing publication without weakening Dashless's editorial, preview, or deployment guarantees. Combine model-led visual judgment with deterministic checks.

## Load the right context

- Read [references/quality-contract.md](references/quality-contract.md) completely for every design, implementation, polish, or audit task.
- Also read [references/dashless-architecture.md](references/dashless-architecture.md) when changing the bundled template, WordPress content mapping, preview behavior, routes, or packaging.
- Verify volatile Astro APIs against current official Astro documentation. Prefer primary accessibility standards such as W3C WAI.

## Establish the target

1. Determine whether the target is the bundled template or an already-generated site.
2. Preserve user changes. A generated site is owned source code; do not overwrite it from the template.
3. Inspect real content shapes before choosing the layout: site title, Pages, post titles, excerpts, categories, tags, media, and long-form body elements.
4. Treat visual work as non-editorial and non-publishing work. Designing, previewing, polishing, or launching a site never authorizes creating, rewriting, or publishing WordPress content.
5. WordPress is the only production content source. Never fabricate or seed Posts, Pages, media, or terms to make a site look populated.

## Direct the design

1. Write a one-sentence concept with a specific visual point of view.
2. Define a small token system for surfaces, ink, muted text, accents, type roles, borders, shadows, spacing, and motion.
3. Choose one signature visual move and repeat it with restraint. Avoid generic component-library styling, arbitrary gradients, excessive rounded cards, and decorative effects with no relationship to the concept.
4. Map WordPress content to reader needs before decorating it. Posts, Pages, terms, media, archives, search, RSS, and errors must all have deliberate states.
5. Use system assets by default. Add dependencies only when they materially improve the result and remain compatible with static deployment.

## Create the visual reference before major theme work

For a new theme, a substantial redesign, or a request to make the theme system
meaningfully better, use the built-in Imagegen tool before implementing the
visual layer. Load the `imagegen` skill and generate one art-direction board for
each theme being changed. Each board should show the same core reader states:

- homepage;
- story archive and taxonomy listing;
- article or Page;
- search results; and
- 404 / empty state.

Use the board to establish composition, hierarchy, type roles, surfaces, color,
spacing, image treatment, and the theme's signature visual move. Keep generated
copy short and schematic; the board is a visual reference, not production
content. Inspect every generated board, iterate targeted defects when useful,
and save project-bound references under `docs/design/theme-boards/` (or the
equivalent design-reference directory for a generated site).

Implement the board's visual decisions in shared Astro components, semantic
classes, design tokens, and scoped theme styles. Do not turn the generated
bitmap into a page background, bake text into production UI, or fork the route
and content architecture merely to match a comp. The implementation should
match the board's visual system while retaining real WordPress content,
responsive reflow, accessible controls, and progressive enhancement.

For a small bug fix or a purely mechanical accessibility/compatibility change,
reuse the existing board and skip regeneration unless the visual direction
itself changes.

## Close the gap between the comp and the implementation

Imagegen is an art-direction reference, not a pixel-perfect renderer. For
high-fidelity theme work, use a deterministic screenshot loop after the visual
board is approved:

1. Generate page-specific references for each changed theme and core state
   rather than relying only on a multi-page contact sheet. Cover the homepage,
   archive/taxonomy, article/Page, search, and 404/empty state. Use fixed target
   viewports such as 1440×1024 and 390×844.
2. Translate each reference into a compact implementation spec before styling:
   canvas and surface colors, ink and accent colors, content width, reading
   width, type roles, spacing scale, borders, radii, shadows, image ratios, and
   breakpoints. Keep this as tokens or a nearby design reference, not as
   undocumented visual guesswork.
3. Match geometry before decoration: page width, header height, hero size,
   column proportions, reading measure, image crops, card heights, and vertical
   rhythm. Then tune typography, surfaces, borders, shadows, and decorative
   marks.
4. Map every reference element to shared Astro markup and semantic classes.
   Keep content and route architecture shared; use theme tokens and variants
   instead of duplicating route implementations or baking copy into images.
5. Capture the real Astro output at the reference viewports. Produce a
   side-by-side image, transparent overlay, and difference image for each
   route/theme pair. Ignore only small antialiasing differences; treat layout
   drift, overflow, or inaccessible controls as failures. Use the project's
   existing image tooling where possible instead of adding a framework.
6. Iterate in order: structure, typography, then surface. Rebuild and recapture
   after each pass, fixing the highest-impact diff before adding detail.

Use representative local QA fixtures during comparison: long and short titles,
stories with and without media, failed media, multiple terms, long excerpts,
and article paragraphs, lists, quotes, code, tables, embeds, and long URLs.
Fixtures remain local and never become WordPress content or deployable fallback
content.

A theme is visually ready only when every changed page state has desktop and
mobile references, the reviewed screenshot diffs are acceptably close, and the
same output passes narrow reflow, 200% zoom, keyboard focus, dark mode, reduced
motion, forced colors, missing-media, and no-JavaScript checks. Imagegen may
inspire the bitmap reference, but deterministic browser screenshots and the
reviewed diffs are the fidelity gate.

### Non-superficial redesign gate

Do not describe a redesign as complete when the result is only a palette,
font, border, or shadow change on the old composition. Before implementation,
write a short acceptance matrix for every changed theme and route state. It
must name the structural differences that make the theme recognizable: header
system, hero geometry, content ordering, card/list treatment, reading column,
archive/search composition, and mobile transformation. At least one visible
structural change must be implemented for each item; a token-only change does
not satisfy the matrix.

When several themes share markup, prove that the shared semantic structure can
express the approved references. If it cannot, add explicit theme variants or
theme-specific components before styling. Do not hide this mismatch behind a
large stylesheet. Inspect the rendered page, not just the source diff, and
reject the pass if two supposedly distinct themes still have the same major
regions, geometry, and hierarchy.

Use a three-state completion record for every change:

1. `local`: source, Astro build, route audit, and screenshot comparisons pass;
2. `builder`: the actual Railway/build-server artifact passes the same checks;
3. `live`: WP Cloud has activated that artifact and a fresh uncached request
   proves its release identifier, expected DOM fingerprint, and selected theme.

Never collapse these states into “done.” Never report a live redesign from a
successful local build or a successful Railway deployment alone. If the live
fingerprint or release header is unchanged, say the old release is still live
and continue debugging or stop with that blocker. A failed preview or builder
job must preserve the previous public release and must be surfaced as a
deployment failure, not worked around with a claim of completion.

For a theme-switcher demo, verify at least two visibly different styles in a
fresh browser context and verify that the selected style survives navigation,
does not leak into the publication's utility-free masthead, and is not a stale
cached HTML response. Add a small machine-checkable fingerprint for the
redesigned shell when practical, so a live check can distinguish the new
composition from the prior one without relying on visual memory.

## Implement safely

- Keep content queries and publication logic separate from presentation.
- Render WordPress-filtered `rendered` HTML on public builds. Keep raw editor HTML only for revision digests and local staged previews.
- Escape normal strings. Use Astro `set:html` only for explicitly trusted or sanitized HTML.
- Give meaningful images useful alt text, decorative images empty alt text, and remote images stable dimensions or layout-stable containers.
- Handle failed media without inline event attributes. The fallback must remain legible in every color mode and must not create layout shift.
- Use semantic landmarks, one page-level heading, visible focus, native controls, adequate touch targets, reduced-motion handling, reflow-safe content, and strong contrast.
- Provide unique titles, descriptions, canonicals, social metadata, structured data where appropriate, RSS, sitemap, robots, favicon, and a useful 404 page.
- Keep search local and tracking-free unless the user requests another architecture. Insert user-controlled strings with text nodes, not HTML.

## Preview real and representative states

Judge the real frontend with content returned by the connected WordPress site. If WordPress is empty, make the empty state useful and intentional; do not add starter content. If existing WordPress content does not cover the shapes below, use isolated local QA fixtures only. Never write fixtures to WordPress, package them into the Astro source as fallback editorial content, or deploy them.

Exercise:

- a long title and a short title;
- posts with and without images;
- missing or failed media;
- multiple terms and an empty taxonomy;
- paragraphs, lists, quotes, code, tables, embeds, and long links;
- search results and no-results states; and
- light and dark palettes.

If browser control is available, inspect the actual local site rather than relying on source alone. Check at approximately 390 px, 768 px, and 1280 px widths, then reset any temporary viewport override.

## Run three polish passes

1. **Composition:** hierarchy, line length, spacing rhythm, density, image ratios, and the transition between hero, stream, sidebar, and footer.
2. **Interaction:** navigation state, keyboard focus, touch targets, theme control, search, links, loading, missing media, and reduced motion.
3. **Edges:** long content, narrow reflow, wide screens, light/dark contrast, forced colors, print, metadata, 404s, and browser-console cleanliness.

After each pass, rebuild and inspect the rendered result. Fix the highest-impact issue before adding more decoration.

## Run the deterministic audit

Build the Astro project first, then run:

```bash
node <this-skill>/scripts/audit-dist.mjs --project /absolute/path/to/site
```

Use `--production --strict` when preparing a release. Production mode rejects loopback preview origins anywhere in generated text assets. Use `--json` for machine-readable output. Fix every error. Resolve warnings or document why they are intentional.

The audit supplements visual review; it does not replace browser testing, keyboard testing, content judgment, or assistive-technology testing.

## Verify and hand off

1. Run `astro check` and the production build.
2. Run the audit script and the repository's complete test suite.
3. Verify representative pages, search, theme switching, internal links, missing media, and console output in the browser.
4. Repackage the plugin when the bundled template or this skill changes.
5. Report what changed, what was tested, any remaining limitations, and the preview or package path.

For production theme work, also report the three completion states (`local`,
`builder`, `live`) and include the live release/fingerprint evidence. If any
state is incomplete, the task is not complete and the response must say so
plainly.

Do not claim universal compliance. State the standards and test surfaces actually checked.

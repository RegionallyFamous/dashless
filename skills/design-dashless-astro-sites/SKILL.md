---
name: design-dashless-astro-sites
description: Design, build, restyle, audit, or polish distinctive Astro publication sites backed by WordPress and Dashless. Use for Astro theme direction, homepage and article layouts, content mapping, responsive behavior, day/night themes, accessible interactions, missing-media resilience, SEO and social metadata, populated local previews, visual QA, or best-practices reviews of a Dashless-generated frontend or its default template.
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

Do not claim universal compliance. State the standards and test surfaces actually checked.

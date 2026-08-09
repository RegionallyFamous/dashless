# Astro publication quality contract

Use this contract as the acceptance rubric for a Dashless publication. It separates taste decisions from requirements that should be verified mechanically.

## 1. Product and content truth

- WordPress remains canonical for Posts, Pages, media, terms, revisions, status, and authorship.
- WordPress is the sole production content source. Never create or import editorial content merely to populate a design, preview, or launch.
- An empty or sparse WordPress response must produce an intentional empty or sparse state. Representative QA fixtures stay local and isolated; they are never written to WordPress or shipped as fallback editorial content.
- The public Astro site serves readers, not editors. Do not reproduce wp-admin or expose editorial controls.
- The design must work with unknown site names, long titles, sparse archives, missing excerpts, absent terms, and broken media.
- A WordPress front Page may supply homepage copy; the homepage must also work when no front Page is configured.
- Preserve chronological archives, nested Pages, category and tag routes, search, RSS, sitemap, robots, and 404 behavior.

## 2. Visual direction

Define before implementation:

1. A one-sentence concept.
2. Three to five adjectives describing the intended feeling.
3. A token palette with foreground/background contrast checked for both palettes.
4. Type roles for display, reading, interface, and code.
5. One signature motif and one intentionally quiet area.

Prefer a coherent visual argument over a pile of effects. Repetition should create identity; variation should communicate hierarchy.

Avoid default AI-site signals unless they fit the concept: interchangeable purple gradients, every section in a rounded card, excessive glow, oversized empty heroes, unexplained glass effects, and decorative dashboards on editorial sites.

## 3. Layout and typography

- Use one `h1` per page and preserve heading order in long-form content.
- Keep article text near 45–80 characters per line at normal zoom.
- Allow titles, URLs, code, tables, embeds, and taxonomy labels to reflow without horizontal page scrolling.
- Keep primary content visible near the first viewport. A large hero must earn its space and lead naturally into recent content.
- Use consistent vertical rhythm. Adjacent sections should not accidentally merge or float apart.
- Use real content while tuning clamp values and breakpoints.

## 4. Responsive states

Inspect at minimum:

| Width | What to verify |
|---|---|
| 390 px | Navigation reachability, title wrapping, single-column reading, touch targets, no horizontal overflow |
| 768 px | Featured-story proportion, grid collapse, sidebar placement, medium-length line measures |
| 1280 px | Maximum width, whitespace balance, hero scale, grid density, sticky elements, footer composition |

Also test 200% browser zoom or an equivalent reflow state. Do not disable zoom. Use horizontal scrolling only inside content that genuinely requires it, such as wide code or data tables.

## 5. Interaction and accessibility

- Use native links, buttons, inputs, headings, lists, time elements, landmarks, and labels.
- Include a visible-on-focus skip link to the main region.
- Make keyboard focus unmistakable in light, dark, and forced-color modes.
- Keep pointer targets at least 24×24 CSS pixels; prefer roughly 44 px on coarse pointers for primary controls.
- Do not communicate state with color alone. Pair color with text, shape, or position.
- Respect `prefers-reduced-motion`; decorative blinking and pulsing must stop.
- Theme controls expose state with `aria-pressed`, update their accessible name, follow the system preference until the visitor chooses, and persist only the visitor's theme choice.
- Search has a programmatic label, useful status text, keyboard submission, safe result rendering, a stable query URL, and a no-results state.

Automated checks cannot prove usability for disabled people. When risk warrants it, add screen-reader and real keyboard testing.

## 6. Media resilience

- Meaningful media has useful alt text sourced from WordPress; decorative media has `alt=""`.
- Set dimensions when known. Otherwise reserve a stable aspect ratio or minimum space.
- Prioritize only genuine above-the-fold images. Lazy-load lower images and decode asynchronously.
- Missing images become intentional placeholders, not broken-image icons.
- A failed meaningful image exposes an accessible unavailable state. A failed decorative image remains ignored by assistive technology.
- Never inject image alt text through `innerHTML`.

## 7. Content security

- Astro `set:html` is an explicit trust boundary.
- Use WordPress `rendered` fields for public HTML. WordPress applies its normal content filters and shortcode/block rendering there.
- Keep raw fields for content digests and revision locking, not public injection.
- Use text interpolation or `textContent` for titles, search queries, result snippets, alt labels, and taxonomy names.
- Do not use inline `onerror`, `onclick`, or similar event attributes. Bind behavior in a processed script.
- Escape `<` in JSON embedded inside script elements.

## 8. Metadata and discovery

Every indexable page should have:

- a unique title and useful meta description;
- an absolute canonical URL;
- Open Graph title, description, type, URL, site name, and image alternative when an image exists;
- an appropriate Twitter card;
- a language declaration and responsive viewport;
- structured data that matches visible content when used; and
- access to RSS and sitemap discovery.

Search and 404 pages should normally be `noindex,follow`. Robots must advertise the absolute sitemap URL. Canonicals must use the configured public origin, not the loopback preview origin.

## 9. Performance and ownership

- Default to static HTML, CSS, and small progressive scripts.
- Avoid a client framework for theme chrome unless interaction complexity justifies it.
- Avoid remote font and analytics dependencies by default.
- Keep generated source understandable and editable.
- Mirror WordPress uploads when configured so releases are immutable and do not depend on a second origin.
- Watch built JavaScript, CSS, and image sizes. Do not optimize away clarity or accessibility.

## 10. Release gate

Before calling the work complete:

- Production build and type checks pass.
- Deterministic audit passes in `--production --strict` mode, with the configured public origin rather than a loopback preview origin.
- Internal links resolve.
- Homepage, archive, article, static Page, taxonomy, search, and 404 render.
- Light/dark, missing media, long text, narrow/wide layouts, and keyboard focus are visually checked.
- Browser console has no errors or warnings caused by the site.
- The previous deployment remains recoverable.

Report exact evidence. Say “checked against” rather than “fully compliant” unless an appropriate independent compliance audit has occurred.

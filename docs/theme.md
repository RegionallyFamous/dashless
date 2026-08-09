# Hypertext Diary theme

Hypertext Diary is Dashless's default Astro presentation: a bright late-1990s personal-web blog with modern editorial typography and accessibility. It uses browser-window framing, offset borders, status strips, hand-labeled archives, “currently” widgets, RSS language, and a user-controlled day/night palette.

The theme deliberately uses CSS shapes and system typefaces. It has no font CDN, JavaScript framework, image pack, analytics service, or theme account.

## Content mapping

- The WordPress site title becomes the masthead.
- A configured static front Page supplies the homepage title and introduction.
- The newest Post becomes the large featured entry.
- Recent Posts fill the transmission grid.
- WordPress categories populate the topic widget and topic directory.
- WordPress Pages populate the main navigation.
- Tags appear on entries and in the tag directory.
- The RSS link is treated as a primary subscription action.

## Navigation transitions

Hypertext Diary uses the browser's native cross-document View Transitions API. The masthead persists visually between pages, while a Post title and featured image can move naturally from an archive card into the matching article. Navigation remains ordinary multi-page navigation: unsupported browsers get an immediate page load, links work without JavaScript, and visitors who request reduced motion receive effectively instant transitions.

Transition names are derived from WordPress Post IDs, so the same editorial object receives the same stable name on the archive and article routes. Keep each name unique within a page when extending the card layouts.

## Generated social cards

Every published WordPress Post receives a 1200×630 PNG share card during the Astro build. It uses only WordPress-backed editorial fields—the Post title, publication date, first category, featured image, and configured site identity—and adds the theme's retro browser framing. If a Post has no usable featured image, the card uses a decorative theme fallback without changing the WordPress record.

Cards have digest-based filenames, are copied into the immutable release, and feed Open Graph, Twitter, and Article structured metadata. They need no image service or server runtime. Rebuilding after a WordPress change creates a new card URL; stale cards are removed from later builds.

## Visual tokens

The palette, type stacks, border weight, and shadows are CSS variables at the top of `src/styles/global.css`. The most useful variables are:

- `--canvas`, `--paper`, and `--panel` for surfaces;
- `--ink` and `--muted` for text;
- `--blue`, `--pink`, `--acid`, `--yellow`, and `--cyan` for the retro accent system;
- `--display`, `--sans`, and `--mono` for typography; and
- `--shadow` and `--shadow-small` for the chunky window effect.

The `[data-theme="dark"]` block is the complete night palette. The theme toggle stores only the local color preference in the browser.

## Accessibility and resilience

- Semantic HTML and a skip link remain intact.
- All interactive controls have visible keyboard focus.
- Motion, including native page transitions, is disabled when the visitor requests reduced motion.
- The day and night palettes preserve strong contrast.
- Article content has dedicated table, code, blockquote, image, and print treatments.
- The search interface inserts results with DOM text nodes rather than raw HTML.
- Public builds use WordPress's filtered rendered HTML while preserving raw editor content only for revision digests.
- Failed WordPress media is replaced with accessible, layout-stable fallback artwork without inline event handlers.
- Generated social cards include image alternatives, and a sitemap, RSS feed, canonical URL, structured data, and favicon ship by default.
- Layouts collapse to a single readable column on narrow screens.

## Customizing generated sites

Generated Astro sites are owned source code. Change the palette, surface behavior, and transition timing in `src/styles/global.css`; shared chrome in `src/layouts/SiteLayout.astro`; homepage modules in `src/pages/index.astro`; entry cards in `src/components/StoryCard.astro`; and share-card composition in `src/lib/social-card.mjs`. WordPress content querying, preview locks, routes, and deployment do not depend on the theme's visual choices.

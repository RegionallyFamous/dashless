# Dashless Astro architecture

Read this when changing the bundled template or any code that maps WordPress content into Astro.

## File map

| Area | Bundled source |
|---|---|
| Shared HTML, metadata, navigation, theme behavior | `templates/astro/src/layouts/SiteLayout.astro` |
| Global tokens and responsive presentation | `templates/astro/src/styles/global.css` |
| Homepage composition | `templates/astro/src/pages/index.astro` |
| Entry cards | `templates/astro/src/components/StoryCard.astro` |
| Archive shell and pagination | `templates/astro/src/components/Archive.astro`, `Pagination.astro` |
| Article and Page rendering | `templates/astro/src/pages/stories/[slug].astro`, `[...slug].astro` |
| Search | `templates/astro/src/pages/search/index.astro` |
| WordPress fetching and normalization | `templates/astro/src/lib/dashless.mjs` |
| Build-generated Post share cards | `templates/astro/src/lib/social-card.mjs` |
| RSS, sitemap, robots, 404 | `templates/astro/src/pages/` |
| Project generation and builds | `server/lib/frontend.mjs` |

Generated projects receive a complete copy of `templates/astro`. Later template changes must not overwrite an existing generated project.

## Content contract

The connected WordPress site is the only source of production editorial content. Theme generation, visual QA, preview, and deployment must not create Posts, Pages, media, or terms. The Astro source must not contain fallback articles or demo copy. Empty WordPress collections render intentional empty states. Local test fixtures may exercise missing content shapes, but they must remain isolated from WordPress and release output.

`canonicalPost()` produces stable raw editorial fields for SHA-256 publication digests. Do not change digest inputs casually.

`normalize()` produces presentation objects. Public `content` and captions should prefer WordPress `rendered` fields; titles and metadata remain escaped by Astro. Preview payloads may be raw strings because they are local, explicit staged previews.

Posts expose:

- IDs, slug, title, content, excerpt, status, dates, digest, and route;
- featured image source, alt text, caption, and dimensions;
- an immutable, digest-named 1200×630 social-card URL and alternative text; and
- resolved category and tag term objects.

Social cards are build artifacts, not editorial records. Generate them from the normalized WordPress Post and site configuration, never by creating or modifying WordPress content. They must work without a remote image service or runtime server and should be copied into the same immutable release as the page that references them.

Pages also expose parent-aware paths plus `isHome` and `isPostsIndex` states.

## Route contract

The generated project owns:

- `/`;
- the configurable posts, topics, and tags roots;
- paginated story archives;
- nested Page paths;
- `/search/`, `/rss.xml`, `/sitemap.xml`, `/robots.txt`, and the 404 output.

Posts, topics, tags, and search roots must remain distinct. Route paths are rewritten during `createFrontend()`, so imports and links should use `config` rather than hard-coded public segments.

## Preview contract

`preview_frontend` builds representative published state for design review. It does not produce publication authority.

`create_preview` builds the real route for a draft or staged change and locks a content digest to a single-use preview token. Visual work must not bypass this contract.

`publish_previewed` may publish only the exact successfully built and approved digest. Do not edit content between preview and publication.

## Deployment contract

Builds are static and immutable. Local and SSH deployments switch atomic releases. WP Cloud stores releases under uploads and activates them through the companion plugin; Astro does not run as a server process on WP Cloud.

Theme code must not assume Node, an Astro dev server, or WordPress is available at request time.

Native cross-document transitions must remain progressive enhancement. Routes are still independent HTML documents, shared transition names must be unique within each document, and reduced-motion preferences must suppress animation.

## Safe extension points

- Change visual tokens, layout markup, components, and presentation scripts freely.
- Add static routes that do not conflict with reserved roots.
- Add progressive client behavior that still leaves core content usable without JavaScript.
- Add tests for new content shapes and routes.

Do not move publishing state into Astro, expose credentials to browser code, add server-only runtime dependencies, weaken stale-write checks, or make deployment destructive.

# Shared Astro publication foundation

Local and hosted Dashless use `templates/astro` as the source of publication behavior. Hosted packaging copies it into `runtime/template`; it does not maintain a separate theme. Existing generated local projects remain user-owned and are never overwritten.

## Layers and boundaries

1. **Content adapters:** `src/lib/dashless.mjs` obtains WordPress REST data locally. `src/lib/snapshot.mjs` provides the same build-time data interface from a sealed hosted snapshot. Both flow through the same normalization, route planning, search indexing, media handling, and share-card generation.
2. **Publication components:** `SiteLayout`, `Archive`, `StoryCard`, `Pagination`, and shared Astro routes own all reader behavior. Static HTML provides reading/navigation; small scripts add search, theme preference, and media fallback. No client UI framework is needed.
3. **Design configuration:** `src/lib/design.mjs` validates schema version 1 separately from the saved design revision. `presets.css` supplies paper/night/lilac palettes, editorial/modern/classic typography, and journal/magazine/minimal layouts. These change presentation without replacing routes. The [theme catalog](theme-catalog.md) adds versioned Hypertext Diary, Field Notes, and After Hours designs with catalog discovery and sample previews. Identity, logo, and page navigation remain data. Arbitrary executable customer code/packages are outside hosted v1.

WordPress remains the editorial source. Preview approval, account authorization, activation, and rollback remain backend responsibilities. A successful frontend test is not evidence that native WP Cloud execution passed.

## Feature matrix

“Required” means the curated publication must contain it. “Optional” requires backend support and an explicit product decision. “Deferred” must not be advertised as available.

| Capability | Status | Implementation | Acceptance evidence |
|---|---|---|---|
| Empty and populated homepage | Required | `pages/index.astro` | Empty/single/archive browser fixtures |
| Static front page and nested pages | Required | `dashless.mjs`, page routes | Front-page and nested-page fixture; local preview test |
| Chronological posts and paginated archive | Required | Stories routes, Archive/Pagination | 14-post fixture; second archive page |
| Topic/tag directories and archives | Required | Topics/tags routes | Route manifest, populated/empty directory checks |
| Search, query URL, safe results, no results | Required | Search route | Browser submission, result count/link and empty result tests |
| Header, navigation, footer, logo | Required | SiteLayout; design config | Nested navigation fixture; adapter media tests |
| WordPress-rendered text, lists, quotes, code, tables | Required | Adapter, article-body styles | Raw/rendered separation and rich-content fixture |
| Core image/gallery/columns/button styling | Required | Global stylesheet | Browser gallery/columns fixture and narrow reflow |
| Mirrored local media, alt text, missing-image fallback | Required | Snapshot/media adapters, SiteLayout | Sealed asset checksum, gallery and fallback tests |
| Social cards, canonical, Open Graph, structured metadata | Required | Social-card generator, SiteLayout | Build gate validates PNG dimensions, metadata and canonicals |
| RSS, sitemap, robots, favicon, 404 | Required | Shared static endpoints | Strict audit and route/discovery checks |
| Light/dark preference, reduced motion, focus/reflow | Required | SiteLayout/styles | Browser checks at 390/768/1280/320; theme persistence, skip link |
| Fast client navigation with a safe full-page fallback | Required | Astro `ClientRouter`, built-in prefetch, idempotent page-load wiring | Browser navigation/back check under the same-origin CSP fixture |
| Baseline browser hardening | Required | Same-origin CSP for hosted runtime; `nosniff`, referrer, and permissions headers on WP Cloud file responses | Hosted syntax gate and protected preview/public response checks |
| Protected preview links and assets | Required for hosted | Runtime rebasing and authenticated site routes | Local protected-prefix/CSP simulation; native host acceptance separately |
| Immutable release and rollback | Required backend | Existing release service | Existing backend tests; not replaced by frontend gate |
| Private notes, notifications, reactions, Webmentions | Optional; no new reader controls in this change | Backend capability-specific integration | Hide controls until endpoint, moderation/privacy, and browser tests exist |
| External interactive embeds/plugin widgets/forms | Deferred in hosted v1 | Provider-specific integration needed | Do not enable arbitrary scripts or weaken CSP to make a widget work |
| Taxonomy pagination, author archives, custom post types, multilingual switching | Deferred | Product extension | Add routes, adapter mapping and fixture before advertising |
| Commerce, memberships, arbitrary hosted themes/packages | Deferred | Separate scope | Not part of publication foundation |

Filtered WordPress HTML is not a guarantee that every plugin's client-side block works headlessly. Standard markup is supported; integrations needing plugin scripts, external frames, or live endpoints need an explicit capability and tests. Missing configured navigation pages, route collisions/cycles, absent rendered content, invalid designs, and corrupt referenced assets fail the build rather than silently dropping content.

## Build and release gates

`npm run build` in generated sites runs Astro type checks, a production build, and `scripts/check-publication.mjs`. The latter verifies the generated `dashless-publication.json` route inventory, article/social output, search, discovery files, local assets, and the strict deterministic audit. Public origins reject loopback URLs; intentional local-only previews use the local audit mode. The skill's audit command delegates to the same implementation.

Hosted runtime calls the same gate before creating a successful build result. `frontend_contract: 1` and `quality.passed: true` are required before the site service accepts a candidate. Rebased artifacts remain subject to manifest hashing and approval binding. Type checking the curated hosted source belongs in CI/package validation; native execution strategy is owned by the runtime service.

Run from the repository:

```sh
npm ci
npx playwright install chromium
npm run check
npm run test:frontend
npm ci --prefix hosted/runtime
node --test hosted/runtime/test.mjs
```

Browser evidence is written to ignored `dist/frontend-qa/`. Fixtures live under `tests/fixtures/publication/`, are never sent to WordPress, and are excluded from shipped templates/runtime. Browser checks cover three real builds and all 27 presentation combinations for narrow overflow and selected small-label contrast in light/dark themes; they do not claim a complete accessibility audit or exhaustive visual approval of every combination. External service behavior and native WP Cloud acceptance require separate evidence.

## Adding a feature

1. Declare required/optional/deferred status here and identify the owning component/backend.
2. Add the adapter fields without changing editorial digest inputs accidentally.
3. Add representative and failure fixtures before enabling the feature.
4. Implement the shared route/component; keep design choices in configuration/styles.
5. Extend `check-publication.mjs` when output can be checked mechanically; add browser coverage for interactions.
6. Verify both adapters and protected preview URLs, then run relevant native-host acceptance tests.

User-facing controls describe reader actions: “Search this site,” “Read story,” “Return home.” Build/snapshot/digest terminology belongs in maintenance diagnostics, not public reader copy.

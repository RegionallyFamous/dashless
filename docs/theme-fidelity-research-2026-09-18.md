# Theme fidelity research

Date: 2026-09-18

## Finding

The current theme work is passing its functional checks against the wrong visual
reference.

The six checked preview screenshots are a family of “Sunday Journal” layouts.
The approved Imagegen board at
`docs/design/theme-boards/all-themes-imagegen-style-board-2026-09-18.png` is a
different art direction: each theme has its own masthead, hero composition,
image treatment, card geometry, information density, and mobile transformation.
The current browser matrix therefore proves route coverage and CSS stability,
but not fidelity to the approved comps.

This explains why more theme-specific CSS has not solved the problem: the
implementation is refining a shared composition instead of translating the
composition in the board.

## Evidence from the current source

- `BulletinHome.astro` owns the homepage composition for all six catalog themes.
- `SiteLayout.astro` uses one bulletin shell whenever the theme registry says
  `template: "bulletin"`.
- `bulletin.css` is the shared base plus a large style-lab override layer. It
  contains repeated selectors for `data-demo-style` and later duplicate blocks
  for the same themes.
- The browser matrix passes all six themes and seven route states, but its
  screenshots visibly retain the same editorial regions: one bulletin header,
  one intro, one lead block, one latest list, one department block, and one
  follow block.
- The Imagegen board instead shows materially different structures: a utility
  rail for Hypertext Diary, image-led split layouts for Field Notes and After
  Hours, a compact dispatch list for Bulletin, a framed reading-room surface
  for Sunroom, and a dense black-and-white press grid for Mono Press.

## Research conclusions

### 1. Treat the board as a specification, not as a screenshot to imitate with overrides

Imagegen is useful for visual direction, but it does not provide reliable DOM,
responsive behavior, content semantics, or exact geometry. Each board must be
converted into an implementation sheet with measurable decisions:

- max canvas and content width;
- header height and navigation arrangement;
- hero regions, column ratios, and image aspect ratios;
- display, reading, interface, and metadata type roles;
- spacing scale and rule weights;
- card/list geometry;
- article measure and media behavior; and
- explicit mobile reflow.

The implementation sheet should live beside each board and be the source for
the screenshot comparison. Do not infer the spec from the existing CSS.

### 2. Separate semantic structure from visual variants

Keep one content and route system, but introduce explicit semantic slots that
can express the board:

- `PublicationHeader`;
- `PublicationHero`;
- `LeadStory`;
- `StoryCollection` with list/grid variants;
- `TopicIndex`;
- `ArticleShell`; and
- `PublicationFooter`.

Theme variants should select composition and order through these slots. A
theme-specific component is justified when the approved composition cannot be
expressed by the shared semantic structure. This is preferable to hiding a
structural mismatch behind increasingly specific selectors.

### 3. Stop using the style lab as production architecture

The style lab is useful for comparing directions, but it currently behaves as
the de facto production theme system. Its repeated `data-demo-style` blocks
make order and specificity part of the design logic. CSS cascade layers are
available in current browsers and are specifically intended to separate base,
component, theme, and override concerns; they should be used to make that
precedence explicit.

The production path should instead be:

```text
base reset/tokens
  -> shared semantic components
  -> one named theme layer
  -> responsive theme rules
  -> accessibility/utilities
```

Each theme should have one authoritative implementation block. The old style
lab can remain only as a development comparison tool, or be removed once the
real themes are implemented.

### 4. Match geometry before decoration

The most visible current drift is structural, not chromatic: shared hero
height, shared lead block, shared latest-list rhythm, and shared footer furniture
remain present even when the board calls for a different composition. The next
pass must compare bounding boxes and line wrapping before tuning colors,
shadows, or borders.

For every theme and required route, capture 1440×1024 and 390×844 output and
compare:

- canvas and content bounds;
- header and hero bounds;
- lead/story collection bounds;
- image crops;
- headline line breaks;
- reading column width; and
- mobile stacking order.

The visual gate should include a side-by-side review and a pixel/difference
image. A passing build or a passing route test is not visual evidence.

### 5. Use real content variability during visual QA

The board is schematic, but implementation must survive WordPress content. The
fixture set needs long and short titles, missing media, multiple taxonomy terms,
long excerpts, long URLs, and rich article blocks. The fixture must stay local;
it must never become fallback editorial content.

## Recommended implementation order

1. Freeze the current dirty worktree and preserve unrelated deployment changes.
2. Create six page-specific reference sheets from the approved board, starting
   with homepage, archive, article, search, taxonomy, and 404/empty states.
3. Rebuild the shared semantic slots and make the board's structural differences
   possible without CSS contortions.
4. Implement one complete theme first. Field Notes is the best calibration
   candidate because its board makes hierarchy, measure, rules, and image
   treatment easy to inspect.
5. Add the remaining themes one at a time, using the same route fixtures and
   screenshot diff process.
6. Remove or quarantine duplicate style-lab rules after the production theme
   output matches the board.
7. Re-run functional, accessibility, responsive, and release checks. Report
   `local`, `builder`, and `live` separately.

## Platform guidance used

- Astro scopes component styles by default and supports explicit global styles;
  use scoped component styles for component-owned rules and a small global
  token/reset layer for publication-wide rules.
- CSS cascade layers provide explicit precedence between base, component,
  theme, and utility styles; relying on selector specificity and file order is
  the wrong tool for a multi-theme system.
- Browser screenshot comparison is the appropriate fidelity gate because it
  evaluates the rendered DOM, layout, typography, media, and responsive state,
  not just whether source CSS contains theme names.

## Bottom line

The next pass should not be “more CSS until the old thumbnails look nicer.” It
should be a structural translation of the approved board into shared semantic
slots plus six honest theme variants, verified with page-specific rendered
comparisons.

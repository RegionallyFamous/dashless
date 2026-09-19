# Theme implementation specifications

These are the geometry-first acceptance decisions for the six themes. The
Imagegen board is the art-direction reference; these measurements are the
implementation contract used by the Astro screenshot loop.

## Shared route matrix

Every theme must render these states at 1440×1024 and 390×844:

| State | Required structural proof |
| --- | --- |
| Home | distinct masthead, hero, lead, story collection, topic/follow region |
| Archive | theme-specific index header and story ordering |
| Article | theme-specific title/metadata/media/reading column |
| Search | theme-specific search control and result rhythm |
| Taxonomy | term index or filtered archive composition |
| 404 / empty | intentional editorial empty state |
| RSS | valid XML, not a screenshot state |

## Hypertext Diary

Concept: a personal web notebook with a visible utility rail and small signs of
browser furniture.

- Canvas: pale lilac, framed publication surface, 1280–1360px maximum.
- Header: compact wordmark, utility identity, navigation aligned to the right.
- Hero: two-column introduction; large sans title on the left, short description
  on the right; utility rail remains visible on desktop.
- Lead: outlined, offset-shadow panel; image and copy can split horizontally.
- Collection: compact three-column cards on desktop, one column on mobile.
- Article: framed title block with a narrow reading column and persistent utility
  cues.
- Mobile: hide the rail, retain a compact horizontal utility bar, stack lead and
  cards without horizontal overflow.

## Field Notes

Concept: a literary field journal with generous measure, rules, and image-led
observation.

- Canvas: warm paper, 1120–1200px maximum.
- Header: centered serif masthead with a ruled navigation band below it.
- Hero: centered italic display title and quiet description.
- Lead: full-width image when available, then a ruled three-column editorial
  row: label, title, action/context.
- Collection: ruled three-column dispatch grid with restrained metadata.
- Article: large serif title, generous top space, 45–75 character reading measure.
- Mobile: preserve the literary hierarchy; collapse editorial columns to one
  reading stream.

## After Hours

Concept: a nocturnal culture desk with oversized display type and acid rules.

- Canvas: near-black, 1440–1500px maximum, light ink and acid accent.
- Header: compressed uppercase masthead with loud navigation and bottom rule.
- Hero: oversized uppercase title, left aligned, with a short quiet dek.
- Lead: image-led split panel with grayscale/contrast treatment and strong top
  accent rule.
- Collection: four-column dense magazine grid with hard separators.
- Article: oversized uppercase title followed by a left-anchored reading column.
- Mobile: single column; preserve the acid rules and high-contrast hierarchy.

## Bulletin

Concept: a restrained independent publication translated from print.

- Canvas: warm neutral paper, 1220px maximum.
- Header: compact serif masthead, edition identity, utility navigation.
- Hero: direct publication title and short description with generous whitespace.
- Lead: quiet bordered feature with image when available and restrained action.
- Collection: chronological dispatch list with metadata, title, summary, and
  action columns.
- Article: print-inspired title block, annotation-like metadata, centered reading
  measure.
- Mobile: one dispatch stream with compact utility controls.

## Sunroom

Concept: a collected reading room with tactile paper surfaces and terracotta
signals.

- Canvas: warm outer surround and rounded inner publication frame.
- Header: soft serif wordmark and low-contrast navigation.
- Hero: rounded tinted panel with large literary title.
- Lead: rounded light paper card with generous padding and soft shadow.
- Collection: rounded tactile cards in a three-column grid.
- Article: soft framed title/media treatment and comfortable literary reading
  column.
- Mobile: rounded single-column reading room with enlarged controls.

## Mono Press

Concept: a confident black-and-white front page with one red editorial mark.

- Canvas: black/near-black, 1480px maximum, paper ink and red accent.
- Header: oversized compressed masthead, hard rule, edition marker.
- Hero: extreme uppercase display title with strict baseline and dense spacing.
- Lead: red editorial panel with hard border and image treatment when available.
- Collection: rigid four-column press grid with visible rules.
- Article: full-width title field, left-anchored sans reading column, explicit
  metadata.
- Mobile: one-column press page retaining rule hierarchy and red mark.

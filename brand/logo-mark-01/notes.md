# Dashless — folded-cheek bear

A standalone mark to accompany the Hot Type wordmark. The bear head becomes the page: its lower-right cheek folds inward. The two round ears preserve the mascot connection; the compact squared silhouette and one page fold make a simpler emblem than a bear holding a document.

## Files

- `mark-ink.svg`: standard mark, Ink #24212B, transparent cutouts.
- `mark-paper.svg`: reversed standard mark, Paper #F7F2E8.
- `mark-black.svg`: pure black for a monochrome lockup or print preparation.
- `mark-small.svg`: simplified face for 16–24 CSS px; omits the nested muzzle/nose construction.
- `build-mark.py`: editable, deterministic SVG construction.
- `index.html`: mark, color treatments, wordmark combination, and size proofs.
- `size-proof.png`: 1× browser screen proof including the combination and 16/24/32/48/96px sizes.

## Use

Use the standard mark at 32px and above. Use the simplified mark below 32px. Keep clear space of at least one ear diameter. Do not add a surrounding badge, line of tiny text, or dashboard drawing. Treat the mark as one color; the face and fold are transparent negative spaces.

The screen presentation pairs the vector mark with a viewport of the generated Hot Type wordmark. The wordmark itself remains raster concept artwork; this does not create a final vector combination logo. Physical print performance has not been tested.

## Development

Built-in imagegen explored a bear with a separate document panel. Marge recommended making the page the bear's face instead. The revised compact emblem was drawn directly as editable SVG paths, removing the document panel and its horizontal bar. `bear-page-v1.png` and `bear-page-v2.png` are superseded explorations; they are not the selected mark.

Screen proofs preserve the 16–24px simplified construction alongside the standard face. The folded corner is more legible at larger sizes; at favicon size the bear silhouette is the main recognition cue.

## Final independent review

Marge approved presentation as a standalone mark concept. The shared bear/page silhouette and folded cheek sit comfortably beside Hot Type. The 32–96px proofs retain the face and fold; the 16–24px variant preserves the bear silhouette, with the fold reading as a notch at 16px. No material correction was requested. Scalable SVG artwork is supplied; the raster wordmark lockup and untested physical print performance remain separate limits.

# Generation notes

Tool: built-in `image_gen.imagegen`, using the imagegen skill. No fallback CLI.

## Identity board

The original prompt is preserved verbatim in `image-prompt.txt`.

First output: `identity-board.png`. Rejected for dark lighting overlays that obscured the wordmark and palette labels.

Selected revision: `identity-board-v2.png`.

Edit prompt:

> Edit this identity board to correct the severe lighting and contrast defects. Preserve its exact graphic design arrangement, words, dashless wordmark shapes, typography, mascot anatomy and pose, colors and applications. The entire background MUST be uniform opaque light warm cream #F7F2E8. Remove EVERY dark shadow, every dark foggy patch, all gradients and glow and vignette everywhere. This is a perfectly flat vector-like graphic design sheet, not a photographed board. Top wordmark and tagline pure opaque near-black on uniform light cream, highly readable. Top right small headline must read exactly 'THE DASHBOARD IS OPTIONAL.' in dark ink on cream. Entire bottom color palette labels fully legible dark ink on cream. Poster stays solid acid yellow with dark text. Middle card stays flat solid near-black with white dashless lettering and acid yellow label. Right website specimen stays flat cream with dark text, without any drop shadows on type. Preserve the bear but flatten all illumination. No shadows at all. No black background except the middle rectangular card. All text exceptionally clear. Maintain whole board, do not crop.

The revised board is usable as a concept reference. Its printed Ink label drifted to #1A1A1B; authoritative implementation Ink is #24212B as specified in `tokens.css`. Minor raster texture remains. Final campaign artwork should replace the crossed-out content labels with abstract menu strokes or “Admin” to avoid suggesting that Dashless removes content. Logo lettering remains a generated study pending vector drawing.

## Standalone mascot

File: `editor-bear.png`. Reference: the corrected identity board.

Prompt:

> Use the bear in the top right of this Dashless identity board as the exact character reference. Generate a standalone landscape 3:2 campaign illustration on perfectly uniform opaque warm paper #F7F2E8. Only the apricot teddy bear confidently stepping out of an open black outlined software-window frame while carrying its large vermilion editorial pencil. Match its round ears, cream muzzle, dark oval eyes, dark outlined rounded paws, cheeky small smile and determined raised eyebrows. Clear correct anatomy: exactly two arms, two legs, one head; right hand grips pencil, other arm reaches left; one foot steps forward, the other stays behind. Make facial expression mischievous and inviting, not angry. Broad near-black outlines, flat apricot fills, subtle screenprint grain inside fur only, excellent intentional graphic illustration. Compose the bear and frame toward the center, whole silhouette fits with generous 12% empty cream margin on every side. This is the single illustration extracted conceptually and redrawn cleanly, NOT the whole brand board. No wordmarks, no lettering, no palette, no text, no gradients, no drop shadows, no lighting effects, no extra limbs. Slightly opened/broken frame with only 3 abstract frame fragments, no violent destruction. Keep pencil clearly readable. This is a reusable mascot asset.

The mascot is an opaque cream-background PNG, not a transparent cutout or vector master. Inspected for coherent anatomy and pencil grip. The standalone image is more tightly framed vertically than requested but keeps the complete silhouette. Use it in generous page spacing as shown in the guide.

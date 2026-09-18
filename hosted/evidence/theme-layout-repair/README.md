# Theme layout repair

Corrected shared heading line height/tracking, reserved layout space for the Hypertext Diary sticker, removed the empty featured-card column when no image exists, and removed forced card-copy heights. Field Notes and After Hours now use neutral metadata and simpler rules instead of inherited retro labels. Header navigation can wrap independently of the site title.

Verified the three default editions at 320, 390, 768, and 1280px on the homepage, archive, and article (36 browser checks through Codex browser). No page overflow, card content overflow, empty lead columns, or hero sticker/headline collisions. Inspected all three desktop and mobile homepages. Theme selection tests and the generated Astro build test passed (4 tests). Added collision assertions to the existing frontend browser suite; the complete suite was not rerun in this repair.

Railway deployment: 5c8a731b-0c36-4e11-ada8-afc1b51e1ea1. Only the two CSS files were replaced in the existing deployment context. Catalog screenshots updated. Immutable packages were patched from the previously published archives, preserving unrelated deployed code; every manifest entry was rechecked. Package hashes and before/after pointers are in packages.json.

Existing static public releases must be rebuilt to receive layout fixes. This repair does not automatically publish customer drafts or activate releases.

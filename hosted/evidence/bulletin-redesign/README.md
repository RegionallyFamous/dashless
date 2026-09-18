# Small Problems — complete Bulletin redesign

Replaced the layered Field Notes presentation with a new, independent Bulletin edition. The renderer loads only bulletin.css for this edition. New compact masthead, editorial introduction, photographic lead, structured story list, topic directory, RSS section, and footer; full archive, article, page, search, and responsive styles. Existing content and media preserved. Small Problems identity/copy is limited to its hostname; other Bulletin users retain their own title and description.

Verification: 22 HTML routes at 320/768/1280 (66 browser checks), no horizontal overflow, one h1 per page, no broken loaded images. Inspected desktop and phone homepages and article layout. Search returned matches, day/night mode worked. Astro build/publication checks passed; all three catalog tests passed. All four editions built successfully on live Railway (railway-themes.json).

Railway: 17d4723f-5ae7-4712-9c34-dfd8e4d5b19c.
Live Small Problems release: 20260917T010420000Z-e32a63, public verification passed. Tasks 759876 and 759878 built the private candidate; 759879 activated it after the explicit owner redesign request. Seven published posts, two pages, one media record; no draft publication target. Prior design/release recorded in operator_backup/before-bulletin; previous verified release remains available for recovery. Temporary WP-CLI-only maintenance files removed.

Catalog/preview and portable source updated on the existing site and Hub. New immutable runtime/site packages verified by every manifest entry; site archive additionally verified over public HTTPS. Package changes contain only theme files, metadata, preview image and manifests; unrelated application code is preserved.

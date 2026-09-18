# Making a Dashless theme

Themes are intentionally small. A theme is a JSON manifest plus CSS selectors;
the shared Astro templates continue to provide posts, pages, RSS, metadata,
accessibility, and responsive behavior.

## 1. Copy the starter manifest

Add one object to `src/lib/themes.json` using the shape below. Keep `id`
stable forever; it is saved with a site’s design settings. Increase `version`
when the meaning or layout of an existing theme changes.

```json
{
  "id": "my-theme",
  "version": 1,
  "template": "standard",
  "name": "My Theme",
  "description": "One clear sentence about the feeling and the reader.",
  "concept": "One sentence describing the visual point of view.",
  "mood": ["specific", "human", "memorable"],
  "signature": "One visual move that gives the edition its identity.",
  "quiet_area": "The part of the site intentionally kept restrained.",
  "best_for": ["personal blogs"],
  "defaults": {"palette": "paper", "typography": "classic", "layout": "minimal"},
  "customization": {
    "palette": ["paper", "night", "lilac"],
    "typography": ["editorial", "modern", "classic"],
    "layout": ["journal", "magazine", "minimal"]
  },
  "preview_image": "my-theme.png"
}
```

`concept`, `mood`, `signature`, and `quiet_area` are design-direction metadata,
not marketing filler. They keep the catalog legible to people choosing a theme
and force each edition to make a coherent visual argument. Use three to five
short mood words; describe one repeated signature move and one intentionally
quiet area.

Use `template: "standard"` for the shared home/archive/article structure.
Choose `bulletin` only when the theme needs a genuinely different content
composition and has a matching component in `src/components/`.

## 2. Add scoped styles

Put the visual changes in `src/styles/themes.css`, scoped beneath
`html[data-design="my-theme"]`. Do not style content by page URL, post title,
or an author name. Use the existing semantic classes (`.hero`, `.story-card`,
`.article-body`, `.site-footer`) so every content type stays covered.

## 3. Add the preview image

Place a 4:3 PNG in `src/assets` (and the corresponding hosted preview asset)
with the exact `preview_image` filename. It should show real layout, not a
marketing mockup.

## 4. Validate before shipping

Run `npm test -- tests/themes.test.mjs tests/frontend-browser.mjs`. The theme
registry rejects duplicate ids, unsupported templates, missing defaults, and
customization lists that cannot reproduce the defaults. The browser suite
renders every theme with empty, single-post, and archive content at desktop and
mobile widths.

Avoid adding a new one-off setting unless it belongs in the shared design
contract. New themes should feel different through composition, type, color,
and spacing—not by forking the entire site.

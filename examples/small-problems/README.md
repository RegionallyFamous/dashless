# The Department of Small Problems

A funny, fictional sample blog for showing customers what a Dashless publication can look like.

**No concern too minor. No investigation on schedule.**

Includes seven original stories (one illustrated chair investigation), an About page, a nested staff page, four topic collections, tags, paginated archives, working search, light/dark preference, RSS, social images, and a custom 404 page.

## Live example

[Read The Department of Small Problems](https://smallproblems.dashless.blog/). Hosted on its own WP Cloud site; linked from the Dashless homepage with actual desktop/mobile screenshots.

## Open locally

From this directory:

```sh
npm ci
npm run build
npm run preview
```

Open [the local demo](http://127.0.0.1:4327/). To edit and see updates, use `npm run dev` instead. This preview command runs locally; the public example above is deployed separately.

## Where things live

- `demo/content.mjs`: the original fictional stories and pages.
- `src/styles/global.css`: colors, typography, page layouts, responsive behavior.
- `src/layouts/SiteLayout.astro`: shared header, navigation, metadata, theme control, and footer.
- `src/pages/index.astro`: the customer-demo homepage.
- `demo/media/chair.png`: original image created with the built-in image-generation tool.
- `demo/image-prompt.md`: the image prompt and provenance.
- `demo/art-direction.md`: the creative brief and palette.
- `demo/evidence/`: screenshots and the browser verification report.

## Deliberately fictional

This sample was explicitly commissioned as demonstration content. It has a visible demo label and an explanation on its About page. The staff, department, investigations, and photograph are fictional. It does not collect submissions, email addresses, or payments.

The sample uses its own authored content file, converted at build time into an isolated snapshot for the shared Dashless frontend adapter. **Nothing is written to WordPress.** This is an owned example project, not fallback content in the customer template. Production blogs continue to obtain their real editorial content from WordPress.

The public origin is `https://smallproblems.dashless.blog`. This reader demo publishes the authored fictional snapshot as static files; it does not claim that a customer completed ChatGPT editing, billing or automated provisioning. `deploy/demo-reader.php` serves the homepage and branded 404 through WordPress while the edge serves story and asset files.


## Verification

`npm run build` runs Astro type checks, builds the site, and checks routes, assets, search, feeds, metadata, social cards, and the strict publication audit.

The browser check uses the repository's Playwright installation. From the repository root, with the preview running:

```sh
node examples/small-problems/demo/verify.mjs
```

It covers the 23 pages at 390, 768, 1280, and 320 pixels, search, pagination, nested pages, theme persistence, keyboard access, image failure, and reading without JavaScript. Screenshots are saved under `demo/evidence/`.

The source was created from the shared Astro foundation and customized as an independent example. Existing customer sites and the shared template were not changed for this demo.

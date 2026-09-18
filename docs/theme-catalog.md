# Dashless theme catalog

The hosted app offers four versioned starting points: **Hypertext Diary**, **Field Notes**, **After Hours**, and **Bulletin**. They share WordPress content adapters, routes, search, feeds, accessibility behavior, and the protected preview/publication flow. Each has its own presentation and default palette, typography, and layout.

## Customer workflow

During setup or redesign, the MCP instructions direct ChatGPT to call `list_themes`, describe the choices, and show their sample preview images. `get_theme` returns the selected edition, defaults, supported adjustments, and sample screenshot. These are public illustrative previews, never customer content.

Read `get_design`, then call `update_design` using its `expected_version`, a fresh `client_key`, and changes such as:

```json
{"theme_id":"field-notes","theme_version":1,"palette":"lilac"}
```

Selecting an edition applies its defaults, then the explicit overrides in the same request. Existing title, description, logo, navigation, and WordPress content remain unchanged unless explicitly supplied. Later customization omits the theme fields so it does not reset other adjustments. Re-selecting the same edition intentionally resets its presentation defaults. Unknown themes, unsupported editions, incomplete selections, and stale design revisions fail without saving.

Use `create_preview` with the returned design revision to show the customer's actual content. Existing authenticated publication approval is still required. Theme choice and overrides are included in the sealed design hash; changing them invalidates prior approval. Existing designs with no theme fields retain Hypertext Diary styling. Theme selection requires the `theme_catalog_v1` site capability, including when invoked through `update_design`.

## Authoring and packaging

`templates/astro/src/lib/themes.json` is the catalog source. Each manifest carries a concept, three to five mood words, a signature visual move, and a deliberately quiet area in addition to defaults and supported adjustments. Add metadata and defaults there, implement presentation in `src/styles/themes.css` and shared components as needed, and extend social-card styling. The catalog is copied into the site plugin by `hosted/runtime/package.mjs` and into the Hub by `hosted/chatgpt/scripts/package.mjs`; Astro reads the same source. Do not remove old supported editions when introducing upgrades without a migration policy.

`npm run test:frontend` builds each edition, checks routes at four viewport widths, exercises search and color preference, and renders screenshots to `wordpress/hosted/theme-previews/`. Those images ship with the site plugin and Hub. The Hub serves catalog reads for every authenticated Dashless account, including accounts without a provisioned site, and returns public HTTPS preview asset URLs. Applying a selection still requires a ready site. The same content fixture appears in each preview; it is never written to WordPress. Generated mobile evidence stays in `dist/frontend-qa/`. Review screenshots before releasing a new edition.

`node --test tests/themes.test.mjs` verifies catalog validation, tool schemas, stale revisions, explicit overrides, reset behavior, and identity preservation. `npm run check:hosted` checks hosted syntax and tool contracts. `hosted/scripts/build-contract.mjs` is the source for the generated MCP contract.

Deploy the updated Hub, site plugin, and build runtime together to expose the catalog and render it. This implementation adds supported customization; it does not execute arbitrary customer-generated frontend code. Deployment and submission findings are recorded in `hosted/docs/themes-deployment-2026-09-16.md`.

Bulletin uses its own complete stylesheet, compact publication header, lead story, dispatch list, and article typography. Small Problems uses this edition; its September redesign evidence is in `hosted/evidence/bulletin-redesign/`.

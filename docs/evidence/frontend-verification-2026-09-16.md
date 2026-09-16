# Shared frontend verification — September 16, 2026

This record covers the shared Astro source and local test environments. It does not certify native WP Cloud build execution or authorize publication.

- `npm run check`: **45 passed, 0 failed**, including generated-site installation, type checking, strict publication audit, draft preview, publication, and empty-state rebuild.
- `npm run test:frontend`: **3 contract tests passed**; three actual fixture builds passed browser verification. Empty, single-story, and 14-story/nested-page sites exercised 26 route scenarios at 390, 768, 1280, and 320 CSS pixels (104 route/viewport visits).
- Browser assertions passed for one heading, no horizontal page overflow, keyboard skip link, theme state/persistence, search counts, query URL, protected result links, no-results messaging, meaningful/decorative image fallback, and reading with JavaScript disabled. No unexpected console errors/warnings were recorded.
- All 27 palette/type/layout combinations passed narrow overflow checks and selected small-label contrast checks in light and dark mode. Reduced-motion and forced-color modes were exercised; this is not a full accessibility certification or a screen-reader audit.
- `node --test hosted/runtime/test.mjs`: **3 passed, 0 failed** locally. The runtime uses the shared source and preserves protected link prefixes while keeping public canonicals. The tests also remove a required output route and confirm rejection.
- `npm run check:hosted`: **54 PHP/JavaScript syntax checks passed**, with 23 scoped tool contracts validated.
- Local Codex and WordPress archives were rebuilt and passed archive integrity checks.

Screenshots and the browser report are generated under ignored `dist/frontend-qa/`. The fixture image is deliberately a tiny solid image; the checks validate routing, dimensions, captions, gallery layout, and failure behavior, not photographic quality.

Native WP Cloud execution, runtime resource limits, and hosted preview/account authorization acceptance remain owned by the site-runtime integration task and require separate evidence. No customer site was published by this frontend task.

## Customer-branding follow-up

Teddy-specific cameo markup, image, source hint, keyboard shortcut, and hidden CSS message are now limited to Teddy-named sites. Ordinary customer sites keep neutral decoration. Browser tests verify both the positive Teddy behavior and the absence of Teddy behavior on ordinary sites. Contrast assertions wait for two painted frames after synthetic theme changes before reading computed colors; thresholds remain unchanged.

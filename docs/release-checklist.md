# Dashless 1.0 release checklist

## Automated gate

- All JavaScript and PHP syntax checks pass.
- The complete Node/PHP/Astro test suite passes.
- The generated Astro project installs from its lockfile and builds representative content.
- The production site audit has no errors and no undocumented warnings.
- The WordPress companion activates on the minimum and current compatibility matrix.
- The Codex and WordPress ZIPs are deterministic in repeated builds, pass archive integrity checks, boot from a clean extraction, and match `dist/SHA256SUMS`.
- The native Dashless skills pass validation.

## Real WP Cloud acceptance

Use a disposable site. Record its hostname privately, but never record credentials.

1. Confirm `static_file_404=wordpress`, key-based SFTP access, WordPress REST authentication, and access to the configured document root (`/srv/htdocs` by default; some chrooted WP Cloud accounts expose it as `/htdocs`).
2. Connect through the loopback setup page and inspect the discovered site contract.
3. Generate the locked Astro frontend and run its complete production audit with a real HTTPS public origin.
4. Create a draft with media and terms, build its exact preview, approve it, publish it, deploy it, and verify both content digest and WP Cloud release ID.
5. Change WordPress outside Dashless, confirm the content-generation mismatch is detected, rebuild, deploy, and verify again.
6. Exercise a failed readiness check or transfer against a deliberately invalid test configuration and confirm the active release is unchanged.
7. Deploy a second valid release, roll back, and confirm the first release is active and WordPress editorial content is unchanged.
8. Re-deploy the latest release. For a disposable acceptance site, disconnect, confirm Application Password revocation, and remove test content or the site. For an explicitly requested production launch, retain only its dedicated revocable credential and record that exception.

## Manual frontend gate

Inspect populated homepage, archive, article, Page, taxonomy, search/results, search/no-results, and 404 routes at approximately 390, 768, and 1280 pixels. Verify both palettes, keyboard focus, skip navigation, theme state, failed media, long content, 200% zoom/reflow, reduced motion, forced colors, print, metadata, internal links, and browser-console cleanliness.

## Release record

Record the release date, commit identifier if applicable, Node/PHP/WordPress versions, WP Cloud test result, package checksums, known limitations, and rollback result in the release notes.

The completed 1.0 live record is in [WP Cloud acceptance — 2026-08-09](wpcloud-acceptance-2026-08-09.md).

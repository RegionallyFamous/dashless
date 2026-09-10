# Incremental WP Cloud releases

The frontend still compiles a complete static site. The transfer is incremental: `release/plan` compares the desired manifest with the current release’s actual files using SHA-256 and size. The uploader packages changed files and the complete manifest into one gzip-compressed tar archive, then uploads it over the existing key-based SFTP connection. No shell access on WP Cloud is required.

`release/assemble` verifies the uploaded archive hash, validates the manifest, streams only listed regular entries into a fresh temporary directory, and copies unchanged files from the pinned base release. It does not extract archive paths or create hard links. Copies are checked again against the desired hashes. A directory rename makes the completed candidate available; the existing activation endpoint verifies every file and WordPress content generation before switching the live pointer. The prior release remains independently usable for rollback. Files removed from WordPress/the build are omitted from the next manifest and candidate.

A bridge update is staged and hash-verified before transfer planning. Hosts without PHP Phar/zlib support use the legacy full-file transfer. Local `tar` is required for bundles. An uncertain assembly response can be retried with the same release ID and archive hash; an existing release is never overwritten. A failed transfer leaves the active release alone. If a reused source changes between planning and assembly, assembly fails closed and a new deploy replans from current files.

## Media cache

Fresh frontends and the Teddy frontend use `src/lib/media-cache.mjs`. Existing customized frontends can adopt the helper without replacing their layout or content logic. The cache lives outside `public/` under `.dashless-cache/` (ignored by Git). Builds continue to clear generated `public/_dashless/media` and `social` directories so only current content is shipped.

Media requests are deduplicated within a build and revalidated between builds with ETag or Last-Modified. Raster images must fully decode before being cached. Cache metadata records the downloaded bytes’ SHA-256, so a partial/changed file triggers an unconditional download. Failed downloads retry once; invalid bytes never replace a valid cache entry. Cache and output writes use temporary files plus rename. Responsive variants are keyed by the original bytes, dimensions, encoding settings, and Sharp/libvips versions.

## Measurements

`build_frontend` returns `duration_ms` and `media_cache`; `deploy_frontend` and `publish_previewed` expose `build_duration_ms`, `media_cache`, and deployment transfer metrics. Bundle deployments report `uploaded_files`, `reused_files`, `uploaded_bytes`, `reused_bytes`, `upload_operations`, `base_release_id`, and `duration_ms`.

A new release prefix still changes many HTML/CSS files. Those lightweight files are compressed together; unchanged image bytes are reused on the server. This change does not attempt partial Astro compilation or content-addressed public URLs.

The cache can be removed to force a clean download. It is disposable and is not the source of editorial content. WordPress remains the source of production content.

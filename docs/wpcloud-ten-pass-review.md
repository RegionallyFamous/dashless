# WP Cloud ten-pass review

Dashless's WP Cloud deployment path was reviewed in ten focused passes before the 0.3 package was produced.

1. **Architecture and invariants** — Added a release manifest containing the size and SHA-256 digest of every generated file. Activation now verifies the complete release rather than checking only `index.html`.
2. **Routing security** — Restricted public URLs to clean HTTPS origins, canonicalized host comparison, rejected duplicate or traversing manifest paths, contained real paths, and refused server-executable output.
3. **SFTP reliability** — Added connection keepalives and bounded whole-release retries, quoted every batch path, rejected control characters, and unit-tested generated SFTP instructions. Fresh immutable releases use `put` because OpenSSH 10.3 rejects `reput` when the destination file does not exist.
4. **Atomicity and rollback** — Stored the immediately previous verified release in the same active-release record, made activation idempotent, verified option persistence, and added guarded rollback.
5. **Caching and verification** — Removed long stale-while-revalidate behavior, added immutable release response headers and validators, required the expected release ID as well as the content digest, and added release-specific cache-busting for edge variants during public verification.
6. **WordPress/WP Cloud compatibility** — Replaced live PHP overwrites with staged bridge files and atomic rename, added hash/version verification, self-upgrade support, OPcache invalidation, and downgrade refusal.
7. **Astro output** — Rebuilt mirrored media from scratch for every build, copied newly mirrored media into the same active build, used immutable release URLs, tested inline and featured images, added AVIF handling, and made the generated 404 page non-indexable.
8. **Setup experience** — Added a read-only readiness check for SFTP, the configured document root's `wp-content`, WordPress REST, bridge state, and single-domain versus alias routing requirements.
9. **Adversarial tests** — Added an executable PHP harness covering successful activation, tampered-file rejection, rollback, traversal containment, symlink escape rejection, and refusal to serve PHP.
10. **Consistency and packaging** — Separated local and remote path validation for cross-platform clients, added HTTP timeouts, synchronized version metadata and documentation, reran all Node/Astro/PHP checks, and rebuilt the verified archive.

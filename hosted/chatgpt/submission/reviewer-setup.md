# Isolated reviewer account setup

No reviewer login has been created on a live service, and no credentials belong in this repository or release ZIP.

1. Use the dedicated review environment on WP Cloud, reachable over public HTTPS, with the exact submission build and two isolated customer sites. Never clone customer content or production credential stores into review sites.
2. Prepare two isolated **WordPress.com** reviewer accounts with verified emails and no unrelated content, then sign each into Dashless through `/sign-in/`. This creates separate Hub subscriber identities through the actual provider callback. Provide a reviewer-friendly login method that meets the portal requirements; do not bypass provider security or use Hub `/wp-login.php` passwords. Customer password and email-link login are disabled. Existing customer identities require an explicit operator link after a verified provider attempt; matching email alone never merges accounts.
3. Through the existing approved operator setup, associate each user with exactly one separately bootstrapped review site and active **test** entitlement. Do not expose setup in MCP or enable live checkout. Keep the production Hub state machine and approvals intact. Coordinate the exact provisioning commands with the site workstream; do not fake readiness or capability flags.
4. Seed only clearly fictional drafts and small licensed/generated fixture images. Establish two releases for the rollback case using the normal preview and human approval process. Confirm no real subscribers, payments, service credentials, or personal data are accessible.
5. Verify each login works from a fresh external browser without an inbox or private network, and that it can grant OAuth, see its own private previews, import media, approve publication/rollback and export. Attempt cross-account identifiers in both directions. Capture public release verification and private asset denials.
6. Put the two login credentials directly in the submission portal's protected reviewer field. Provide the login URL, exact prompts and expected behavior. Do not put passwords in listing copy, screenshots, tool results, logs or this document. Keep accounts valid throughout review; rotate/revoke on conclusion or suspected exposure.
7. Confirm the support contact can reset isolated review data without deleting real customer data. Run the scenarios after every reset and before resubmission.

Submission needs real working credentials. This recipe does not satisfy that requirement by itself.

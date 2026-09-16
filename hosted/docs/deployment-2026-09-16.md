# Dashless service setup — September 16, 2026

Live checkout remains disabled. Customer installations are fixed at two PHP workers with bursting disabled. The dedicated Hub may scale above two workers independently as needed (owner clarification September 16).

## Stripe sandbox

- Parent Dashless account: `acct_1UGI2GCoKsxsmsmq`.
- Isolated Dashless sandbox: `acct_1UGI2QCrq4862LCV`.
- Product: `prod_VGppXCnwbcjQbI` — Dashless Blog.
- Monthly USD price: `price_1UGI9zCrq4862LCV4ezwyV6U` — 999 cents, no trial.
- Customer Portal `bpc_1UGIGuCrq4862LCVd8qV98Ez`: cancellation at billing period end; no plan or quantity changes; return to `https://dashless.blog/account/`.
- Sandbox webhook `we_1UGIJPCrq4862LCVOGImgUzx`, API version `2026-02-25.clover`, targets `/wp-json/dashless-hub/v1/stripe/webhook`. Correctly signed synthetic event returned HTTP 200; invalid signature returned HTTP 400. Real Stripe delivery/payment lifecycle remains untested.
- Keys must come from this sandbox, never the existing unrelated Stripe business. No secret is recorded in this document.

## Domain and hosting

- Cloudflare zone: `7707f50f0fc0c769b31eab4b1048b35f`.
- Namecheap nameservers verified: `art.ns.cloudflare.com`, `jill.ns.cloudflare.com`.
- Apex A records: `192.0.79.140`, `192.0.79.162`, returned by WP Cloud's client/domain get-ips API.
- `www` and `*` CNAME to `dashless.blog`; all web records DNS-only, using WP Cloud's hosting edge.
- Existing imported MX/SPF records preserved; email sending/authentication is not yet validated.
- Hub Atomic site ID: `152161516`, creation job `176846432`.
- Hub initial settings: two workers, bursting zero, PHP memory 512 MB, quota requested 25G, PHP 8.5, DFW.
- Dedicated key-only deployment user: `dashlessdeploy`; private key stored outside the repository in `~/.config/dashless/hub/deploy_ed25519`.
- HTTPS and the branded landing/account pages were verified in the browser. `www` is a WP Cloud alias and redirects to the apex over valid HTTPS. Wildcard DNS resolves to WP Cloud; per-customer hosting/HTTPS still needs provisioning acceptance.

## Deployment observation

The software management endpoint returned success for job `176846841`, but subsequent SFTP inspection showed neither custom plugin nor theme installed. Do not treat that job response alone as proof of installation. Direct SFTP installation and activation are being used for Hub setup; customer automatic package bootstrap still needs its own verified acceptance test.

Direct SFTP upload completed. Native task 758979 activated the Hub plugin, task 758980 activated the theme, task 758981 installed account/sign-in/support/policy pages, task 758983 named the site, and task 758985 configured permalinks. Public landing and account pages render correctly. Hub configuration is in its server `wp-config.php`, with local owner-only backup outside this repository; both checkout flags remain false. Encryption key, fleet credential, and sandbox credentials are never copied to customer sites.

WP Cloud rejected a second simultaneous Hub task with HTTP 409, “Only one in-progress task is allowed at a time.” Fleet-wide task serialization/capacity must be investigated; per-site serialization alone does not prove fleet throughput.

Remaining: test real checkout/payment/provisioning and Hub egress, authenticate mail, confirm key restore/persistence, and complete the acceptance gates. Native task build acceptance is still failing in the separate site-plugin workstream, so paid launch is blocked. Customer sites stay at two workers and zero bursting; the Hub may scale independently.

## Railway build transition

Owner approved Railway build execution, superseding the original all-WP-Cloud runtime constraint. A dedicated Railway worker and persistent volume are deployed; Hub `Jobs`, `Provisioner`, and `Config` updates and the new pinned site package are uploaded. Protected Hub config now contains the builder URL and master key. Customer sites receive only site-specific credentials; WP Cloud fleet and Stripe credentials stay on the Hub. The deployed package SHA-256 is `9e01c7a104f646083f12589a2f2d276c64ec98a834fd76dc68155b75383b9684`.

Fresh sites still install the site plugin, remove sample content, and verify package integrity. The Railway bootstrap skips installing Node and retains the portable frontend for export. Build continuations collect verified archives without holding a PHP process during Node execution. No existing customer or Teddy release was changed. Both checkout flags remain false. Details and acceptance evidence are in `../railway/README.md`.

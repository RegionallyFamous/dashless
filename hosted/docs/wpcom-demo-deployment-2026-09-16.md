# WordPress.com sign-in and public example — September 16, 2026

The public example is https://smallproblems.dashless.blog/ (WP Cloud site 152161766). The Dashless homepage links to it and includes actual desktop and mobile screenshots. Its static reader passed all 23 pages at four viewport widths, plus search, navigation, themes, images and accessibility interaction checks. The example demonstrates the published reader; it does not claim a paid customer provisioning journey.

Customer sign-in uses WordPress.com only. Application 148408 uses https://dashless.blog/auth/wordpress/callback. Credentials are held in protected deployment configuration, outside Git. OAuth state is short-lived, single-use and browser-bound. Dashless requires a verified provider email and keys accounts by stable provider ID; it does not silently merge existing email matches. Provider access tokens are not stored. An explicit operator migration linked the existing operator account after verified consent; live sign-in reached the account screen successfully. The sign-in control includes the WordPress logo and a blue provider button.

Validation: 58 Hub integration checks, 103 ChatGPT integration checks and 84 PHP/JS syntax checks passed. Provider tests use fixtures; the separate operator browser check exercised the live consent and callback. Live marketing checks verified screenshots, the demo link, four responsive widths, the removed email-login endpoint (404), and rejection of an invalid OAuth callback (403). The final provider button was visually checked at 1280, 390 and 320 pixels. Evidence is in `hosted/evidence/live-demo-2026-09-16/`.

This supersedes the earlier launch audit's pending account-provider decision and missing public demo. Paid checkout remains gated; the broader launch audit still applies to payment, fulfillment and submission readiness.

## Homepage consolidation

The homepage now renders WordPress.com provider controls in the hero and pricing sections, an inline account/sign-in section, support, and expandable privacy/terms. Existing sign-in, account, and support routes redirect to the corresponding homepage section. Login defaults to `/#account`; ChatGPT OAuth continuation remains preserved. Homepage responses use `private, no-store` because account controls are personalized. Live checks passed at 1440, 390 and 320 pixels, including policy expansion, legacy redirects and OAuth continuation; the 58 baseline integration checks also passed.

## Editorial homepage redesign

Implemented the Imagegen concept as responsive WordPress markup, with a generated publishing-desk illustration and the actual Small Problems screenshot. Consolidated repeated marketing panels into a hero, three-step strip, example and pricing/FAQ grid. Signed-out visitors see one provider button: “Sign in with WordPress.com,” followed by “WordPress.com for sign-in. Dashless for your blog.” Account controls remain inline for signed-in users; support and policies expand in place.

Microinteractions include the lime headline underline, step-number tilt, image zoom and stamp rotation, button press states, directional links, and accordion reveal. Keyboard focus remains visible and the return-to-sign-in link focuses the provider control. Reduced-motion settings disable animation, transitions and smooth scrolling. Local browser acceptance: 20 checks; syntax validation: 88 checks. Live screenshots cover desktop and narrow mobile layouts in `hosted/evidence/editorial-2026-09-16/`. The generated asset prompt and concept are saved in `docs/design/`.

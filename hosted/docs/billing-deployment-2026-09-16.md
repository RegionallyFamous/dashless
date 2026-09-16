# Stripe billing deployment — September 16, 2026

## Deployed

- Separate Dashless live account `acct_1UGI2GCoKsxsmsmq`; account reports payments and payouts enabled, with business details submitted. The unrelated Izzi’s Gym account was not used.
- Existing active USD 9.99/month price `price_1UGKGcCoKsxsmsmq5NUk6qbj`.
- Explicit Customer Portal configuration `bpc_1UGMB3CoKsxsmsmqdK82wgVG`: invoices, payment-method updates, cancellation at period end; no plan or quantity changes.
- Live webhook `we_1UGMB3CoKsxsmsmq169TaILT`, API version `2026-02-25.clover`, subscribed to the eight supported billing events.
- Production credentials stored in protected configuration, with a protected local backup outside Git. Existing production settings were preserved.
- Checkout success/cancel and Portal returns target the homepage account modal directly.
- Unmapped subscriptions from unrelated Stripe products are ignored rather than retried forever. Ownership mismatches for actual Dashless accounts still fail closed.
- Sandbox endpoint disabled at live cutover. No production account had a Stripe customer; 20 pending sandbox events were retired with an explicit cutover reason, without deleting their records.

## Verified

15 real Stripe sandbox API checks passed: price and portal settings, Checkout creation/reuse, absence of premature entitlement, test-card payment, customer portal, cancel-at-period-end and undo, current invoice payment-intent lookup, failed-provisioning refund/cancellation, and late reconciliation.

6 real Stripe test-clock checks passed: initial payment, successful renewal, failed renewal with grace and unchanged paid-through date, invoice-payment recovery, period-end expiration, and Stripe-reported acknowledgement of webhook events.

An actual hosted Checkout was completed in Chrome using Stripe's synthetic 4242 test card. Stripe reported `complete` / `paid`; the isolated local Hub reported `active` / `provisioning`. This subscription was canceled and refunded and its disposable customer removed. No real money was charged.

Production WP Cloud task 759297 verified live account, price and explicit portal settings from the deployed server. A non-billing synthetic event signed with the live endpoint secret received HTTP 200; an invalid signature received HTTP 400. This proves endpoint wiring, not a live charge.

59 local Hub regression checks and 92 PHP/JS syntax checks passed. The external test scripts are `hosted/tests/stripe-sandbox.php` and `hosted/tests/stripe-renewal.php`; they require an isolated local WordPress and protected sandbox credentials. Do not redirect sandbox traffic to the production live webhook. The original sandbox endpoint is now disabled, so its delivery assertions require a dedicated test endpoint before rerunning.

## Remaining launch boundary

Paid signup is still disabled. The `billing_verified` gate is intentionally not marked passed as a blanket end-to-end certification: the real Stripe tests reconciled into an isolated local Hub and did not complete production customer provisioning. Complete a dedicated staging purchase-to-ready-site journey before recording that final gate. Other required service gates remain outstanding, including provisioning, account isolation, restore, ChatGPT publication and policy approval. Live payment configuration is installed; this record does not claim the full service is ready to charge customers.

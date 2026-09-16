# Custom domains — scope update

Owner requested custom domain support on September 16, 2026. This supersedes the original no-custom-domains launch scope. Marketing describes it as planned for launch until the connection flow is implemented and verified.

Include connection of one customer-owned domain per blog in the monthly plan. Domain purchase and renewal stay with the customer’s registrar. Keep the assigned dashless.blog address as the starting address.

Hub account page must offer domain entry, exact DNS instructions, verification status, and retry/disconnect. Require account ownership and DNS ownership verification before binding a domain; enforce globally unique claims. Use WP Cloud domain APIs for attachment, routing and certificates. Verify HTTPS and canonical redirects before switching the public address. Preserve the current working address if setup fails. Never detach or claim another customer’s domain.

Site plugin and ChatGPT tools must discover the canonical public URL dynamically, so previews, publish verification, media URLs, export and rollback work after a domain change. Return short pending/ready/error states and direct customers to the authenticated account flow for DNS setup.

Acceptance: two accounts cannot claim the same domain; failed DNS/certificate setup leaves the blog reachable; verified domain serves HTTPS; old address redirects correctly; removal and subscription suspension preserve isolation and recovery. Confirm API behavior before implementation. No domain registration sales or registrar credentials in v1.

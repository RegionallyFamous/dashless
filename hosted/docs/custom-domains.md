# Custom domains — scope update

Owner requested custom domain support on September 16, 2026. This supersedes the original no-custom-domains launch scope. The Hub now implements the connection flow for one customer-owned domain per blog.

Include connection of one customer-owned domain per blog in the monthly plan. Domain purchase and renewal stay with the customer’s registrar. Keep the assigned dashless.blog address as the starting address.

The Hub account page offers domain entry, exact TXT verification and routing instructions, verification status, retry, and disconnect. It requires account ownership and DNS ownership verification before binding a domain and enforces globally unique claims. It uses WP Cloud alias, routing, and certificate behavior, verifies HTTPS before publishing the custom URL, and preserves the `*.dashless.blog` address if setup fails. It never detaches or claims another customer’s domain without the owner’s action.

Site plugin and ChatGPT tools must discover the canonical public URL dynamically, so previews, publish verification, media URLs, export and rollback work after a domain change. Return short pending/ready/error states and direct customers to the authenticated account flow for DNS setup.

Acceptance: two accounts cannot claim the same domain; failed DNS/certificate setup leaves the blog reachable; a verified domain serves HTTPS; the platform address remains available; removal and subscription suspension preserve isolation and recovery. No domain registration sales or registrar credentials are collected in v1.

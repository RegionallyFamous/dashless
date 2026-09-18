<?php
// Set to 1 only after the public self-serve launch contract and retention limits are reviewed.
// define('DASHLESS_LAUNCH_CONTRACT_VERSION','1');
// Load before wp-settings.php. Put secrets in the WP Cloud server environment or a protected file outside public web roots.
// Never commit a filled copy. No customer site receives the fleet key, Stripe keys, or Hub signing/encryption keys.
define('DASHLESS_LIVE_CHECKOUT', false);
define('DASHLESS_TEST_CHECKOUT', false); // Enable only with sk_test_ and a test Price.
define('DASHLESS_BLOG_DOMAIN', 'dashless.blog');
define('DASHLESS_HUB_SITE_ID', 0); // Dedicated Hub atomic site ID; never a customer site.
define('DASHLESS_STRIPE_SECRET_KEY', getenv('DASHLESS_STRIPE_SECRET_KEY'));
define('DASHLESS_STRIPE_WEBHOOK_SECRET', getenv('DASHLESS_STRIPE_WEBHOOK_SECRET'));
define('DASHLESS_STRIPE_PRICE_ID', getenv('DASHLESS_STRIPE_PRICE_ID'));
define('DASHLESS_STRIPE_PORTAL_CONFIGURATION_ID', ''); // Explicit cancellation/payment settings.
define('DASHLESS_WPCLOUD_API_KEY', getenv('DASHLESS_WPCLOUD_API_KEY'));
define('DASHLESS_WPCLOUD_CLIENT', getenv('DASHLESS_WPCLOUD_CLIENT'));
define('DASHLESS_ENCRYPTION_KEY', getenv('DASHLESS_ENCRYPTION_KEY')); // base64 of 32 random bytes, stable across restarts/restores.
define('DASHLESS_AUTH0_ISSUER', getenv('DASHLESS_AUTH0_ISSUER')); // https://your-tenant.us.auth0.com or your verified Auth0 domain.
define('DASHLESS_AUTH0_CLIENT_ID', getenv('DASHLESS_AUTH0_CLIENT_ID')); // Regular Web Application for Dashless browser sign-in.
define('DASHLESS_AUTH0_CLIENT_SECRET', getenv('DASHLESS_AUTH0_CLIENT_SECRET'));
define('DASHLESS_AUTH0_MANAGEMENT_CLIENT_ID', getenv('DASHLESS_AUTH0_MANAGEMENT_CLIENT_ID')); // M2M app: read:grants and delete:grants only.
define('DASHLESS_AUTH0_MANAGEMENT_CLIENT_SECRET', getenv('DASHLESS_AUTH0_MANAGEMENT_CLIENT_SECRET'));
define('DASHLESS_AUTH0_MANAGEMENT_AUDIENCE', getenv('DASHLESS_AUTH0_MANAGEMENT_AUDIENCE')); // https://your-tenant.us.auth0.com/api/v2/ — canonical tenant, even with custom login domain.
// Optional host-backed email delivery. Disabled until a dedicated signing key is configured.
define('DASHLESS_AUTH0_EMAIL_SECRET', getenv('DASHLESS_AUTH0_EMAIL_SECRET')); // 32 random bytes as 64 lowercase hex characters; not an Auth0 client secret.
define('DASHLESS_AUTH0_CHATGPT_CLIENT_ID', getenv('DASHLESS_AUTH0_CHATGPT_CLIENT_ID')); // Exact ChatGPT app allowed to request sign-in emails.
define('DASHLESS_AUTH0_EMAIL_FROM', 'signin@dashless.blog'); // Must be an address on the Hub domain; verify delivery before enabling.
// ChatGPT is a separate Auth0 application with exact assigned callback URLs and S256 PKCE.
// API identifier: https://dashless.blog/mcp. Permissions: blog:read, blog:write, blog:publish.
// Browser callback: https://dashless.blog/auth/callback; allowed logout URL: https://dashless.blog/
define('DASHLESS_CHATGPT_URL', getenv('DASHLESS_CHATGPT_URL')); // Published integration connect URL.
define('DASHLESS_SITE_PACKAGE_URL', getenv('DASHLESS_SITE_PACKAGE_URL')); // Immutable HTTPS ZIP on this Hub origin.
define('DASHLESS_SITE_PACKAGE_SHA256', getenv('DASHLESS_SITE_PACKAGE_SHA256'));
define('DASHLESS_SITE_PLUGIN_VERSION', getenv('DASHLESS_SITE_PLUGIN_VERSION'));
define('DASHLESS_SUPPORT_EMAIL', getenv('DASHLESS_SUPPORT_EMAIL'));
define('DASHLESS_PLATFORM_WEBHOOK_SECRET', getenv('DASHLESS_PLATFORM_WEBHOOK_SECRET'));
// Host-only cookies are required: omit COOKIE_DOMAIN or define it as false. Never use .dashless.blog.
define('FORCE_SSL_ADMIN', true);

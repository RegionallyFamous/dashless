<?php
// Load before wp-settings.php. Put secrets in the WP Cloud server environment or a protected file outside public web roots.
// Never commit a filled copy. No customer site receives the fleet key, Stripe keys, or Hub signing/encryption keys.
define('DASHLESS_LIVE_CHECKOUT', false);
define('DASHLESS_TEST_CHECKOUT', false); // Enable only with sk_test_ and a test Price.
define('DASHLESS_BLOG_DOMAIN', 'dashless.blog');
define('DASHLESS_HUB_SITE_ID', 0); // Dedicated Hub atomic site ID; never a customer site.
define('DASHLESS_STRIPE_SECRET_KEY', getenv('DASHLESS_STRIPE_SECRET_KEY'));
define('DASHLESS_STRIPE_WEBHOOK_SECRET', getenv('DASHLESS_STRIPE_WEBHOOK_SECRET'));
define('DASHLESS_STRIPE_PRICE_ID', getenv('DASHLESS_STRIPE_PRICE_ID'));
define('DASHLESS_WPCLOUD_API_KEY', getenv('DASHLESS_WPCLOUD_API_KEY'));
define('DASHLESS_WPCLOUD_CLIENT', getenv('DASHLESS_WPCLOUD_CLIENT'));
define('DASHLESS_ENCRYPTION_KEY', getenv('DASHLESS_ENCRYPTION_KEY')); // base64 of 32 random bytes, stable across restarts/restores.
define('DASHLESS_OAUTH_PRIVATE_KEY', getenv('DASHLESS_OAUTH_PRIVATE_KEY')); // Absolute protected PEM path, readable by PHP + native CLI.
define('DASHLESS_OAUTH_PUBLIC_KEY', getenv('DASHLESS_OAUTH_PUBLIC_KEY'));
define('DASHLESS_OAUTH_CLIENT_ID', getenv('DASHLESS_OAUTH_CLIENT_ID'));
define('DASHLESS_OAUTH_REDIRECT_URI', getenv('DASHLESS_OAUTH_REDIRECT_URI')); // Exact assigned ChatGPT callback.
define('DASHLESS_CHATGPT_URL', getenv('DASHLESS_CHATGPT_URL')); // Published integration connect URL.
define('DASHLESS_SITE_PACKAGE_URL', getenv('DASHLESS_SITE_PACKAGE_URL')); // Immutable HTTPS ZIP on this Hub origin.
define('DASHLESS_SITE_PACKAGE_SHA256', getenv('DASHLESS_SITE_PACKAGE_SHA256'));
define('DASHLESS_SITE_PLUGIN_VERSION', getenv('DASHLESS_SITE_PLUGIN_VERSION'));
define('DASHLESS_SUPPORT_EMAIL', getenv('DASHLESS_SUPPORT_EMAIL'));
define('DASHLESS_PLATFORM_WEBHOOK_SECRET', getenv('DASHLESS_PLATFORM_WEBHOOK_SECRET'));
// Host-only cookies are required: omit COOKIE_DOMAIN or define it as false. Never use .dashless.blog.
define('FORCE_SSL_ADMIN', true);

<?php
namespace Dashless\Hub;

final class Config {
    public const GATES = ['task_concurrency', 'runtime_memory', 'provisioning', 'dns_tls', 'email_delivery', 'oauth', 'tenant_isolation', 'restore_drill', 'chatgpt_published', 'billing_verified', 'policies'];
    public static function get(string $name, mixed $default = ''): mixed {
        $key = 'DASHLESS_' . strtoupper($name);
        if (defined($key)) return constant($key);
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
    public static function flag(string $name): bool { return filter_var(self::get($name, false), FILTER_VALIDATE_BOOL); }
    public static function origin(): string { return untrailingslashit(home_url('', wp_get_environment_type()==='local' ? null : 'https')); }
    public static function resource(): string { return self::origin() . '/mcp'; }
    public static function domain(): string { return (string) self::get('blog_domain', 'dashless.blog'); }
    public static function required(string $name): string {
        $value = (string) self::get($name);
        if ($value === '') throw new Failure('configuration_required', 'This service is not configured yet.', 503);
        return $value;
    }
    public static function blockers(): array {
        $checks = get_option('dashless_hub_gates', []);
        $missing = array_values(array_filter(self::GATES, fn($g) => empty($checks[$g]['passed']) || empty($checks[$g]['evidence'])));
        foreach (['hub_site_id','site_plugin_version','stripe_secret_key','stripe_price_id','stripe_webhook_secret','wpcloud_api_key','wpcloud_client','encryption_key','site_package_url','site_package_sha256','oauth_private_key','oauth_public_key','oauth_client_id','oauth_redirect_uri','chatgpt_url','support_email'] as $key) {
            if (!self::get($key)) $missing[] = $key;
        }
        if (self::get('builder_url') && !self::get('builder_master_key')) $missing[]='builder_master_key';
        return $missing;
    }
    public static function checkoutAllowed(): bool {
        if (get_option('dashless_hub_signup_paused', false) || (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN)) return false;
        if (self::flag('test_checkout')) return str_starts_with((string) self::get('stripe_secret_key'), 'sk_test_');
        return self::flag('live_checkout') && str_starts_with((string) self::get('stripe_secret_key'), 'sk_live_') && !self::blockers();
    }
}

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
    public static function auth0Issuer(): string {
        $issuer=rtrim((string)self::get('auth0_issuer',''),'/');
        if(!preg_match('~^https://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$~D',$issuer))throw new Failure('signin_configuration','Sign-in is temporarily unavailable. Please try again later.',503);
        return $issuer;
    }
    public static function auth0Audience(): string {
        $audience=(string)self::get('auth0_audience',self::resource());
        if($audience!==self::resource())throw new Failure('signin_configuration','The ChatGPT connection needs a configuration update.',503);
        return $audience;
    }
    public static function auth0ManagementAudience(): string {
        $audience=self::required('auth0_management_audience');
        if(!preg_match('~^https://[a-z0-9.-]+/api/v2/$~D',$audience))throw new Failure('signin_configuration','The ChatGPT connection needs a configuration update.',503);
        return $audience;
    }
    public static function domain(): string { return (string) self::get('blog_domain', 'dashless.blog'); }
    public static function required(string $name): string {
        $value = (string) self::get($name);
        if ($value === '') throw new Failure('configuration_required', 'This service is not configured yet.', 503);
        return $value;
    }
    public static function blockers(): array {
        $checks = get_option('dashless_hub_gates', []);
        $missing = array_values(array_filter(self::GATES, fn($g) => empty($checks[$g]['passed']) || empty($checks[$g]['evidence'])));
        foreach (['auth0_issuer','auth0_client_id','auth0_client_secret','auth0_management_client_id','auth0_management_client_secret','auth0_management_audience','hub_site_id','site_plugin_version','stripe_secret_key','stripe_price_id','stripe_webhook_secret','stripe_portal_configuration_id','wpcloud_api_key','wpcloud_client','encryption_key','site_package_url','site_package_sha256','chatgpt_url','support_email','launch_contract_version'] as $key) {
            if (!self::get($key)) $missing[] = $key;
        }
        if ((string)self::get('launch_contract_version')!=='1' && !in_array('launch_contract_version',$missing,true)) $missing[]='launch_contract_version';
        if (self::get('builder_url') && !self::get('builder_master_key')) $missing[]='builder_master_key';
        return $missing;
    }
    public static function checkoutAllowed(): bool {
        if (get_option('dashless_hub_signup_paused', false) || (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN)) return false;
        if (self::flag('test_checkout')) return str_starts_with((string) self::get('stripe_secret_key'), 'sk_test_');
        return self::flag('live_checkout') && str_starts_with((string) self::get('stripe_secret_key'), 'sk_live_') && !self::blockers();
    }
}

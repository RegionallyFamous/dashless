<?php
namespace Dashless\Hub;

/** Customer-owned domain claims, verification, and WP Cloud alias attachment. */
final class Domains {
    public function __construct(private Store $store, private Identity $identity, private Cloud $cloud, private Agent $agent) {}

    public function start(int $owner, string $input): array {
        $a = $this->identity->account($owner);
        if (($a['state'] ?? '') !== 'ready' || empty($a['site_id'])) throw new Failure('domain_site_not_ready', 'Your blog must be ready before connecting a domain.', 409);
        $domain = self::normalize($input);
        if ($domain === ($a['domain'] ?? '') || str_ends_with($domain, '.' . Config::domain())) throw new Failure('domain_reserved', 'Use a domain outside dashless.blog.', 400);
        return $this->store->locked('account:' . $owner, function () use ($owner, $a, $domain) {
            $existing = $this->store->get('custom_domain', $domain);
            if ($existing && (int)$existing['owner'] !== $owner) throw new Failure('domain_claimed', 'That domain is already connected to another Dashless blog.', 409);
            $claim = $existing['data'] ?? ['domain' => $domain, 'token' => bin2hex(random_bytes(20)), 'started_at' => time()];
            $claim['domain'] = $domain;
            $claim['status'] = 'pending_dns';
            $claim['owner'] = $owner;
            $this->store->put('custom_domain', $domain, $claim, $owner, 'pending_dns', time() + 7 * DAY_IN_SECONDS);
            $a['custom_domain'] = $domain;
            $a['custom_domain_status'] = 'pending_dns';
            $a['custom_domain_token'] = $claim['token'];
            $this->identity->save($a);
            return $this->status($owner);
        });
    }

    public function status(int $owner): array {
        $a = $this->identity->account($owner);
        $domain = (string)($a['custom_domain'] ?? '');
        if ($domain === '') return ['status' => 'none', 'domain' => null];
        $claim = $this->store->get('custom_domain', $domain);
        $token = (string)($claim['data']['token'] ?? $a['custom_domain_token'] ?? '');
        $ips = [];
        try { $ips = $this->cloud->routingIps($domain); } catch (\Throwable $e) { /* instructions can still be shown */ }
        return ['status' => (string)($a['custom_domain_status'] ?? $claim['status'] ?? 'pending_dns'), 'domain' => $domain,
            'verification' => ['type' => 'TXT', 'name' => '_dashless.' . $domain, 'value' => 'dashless-verification=' . $token],
            'routing' => ['type' => 'A', 'values' => $ips], 'public_url' => !empty($a['public_domain']) ? 'https://' . $a['public_domain'] : null];
    }

    public function verify(int $owner): array {
        $a = $this->identity->account($owner); $domain = (string)($a['custom_domain'] ?? '');
        if ($domain === '') throw new Failure('domain_missing', 'Start by entering the domain you want to connect.', 400);
        $claim = $this->store->get('custom_domain', $domain);
        if (!$claim || (int)$claim['owner'] !== $owner) throw new Failure('domain_claim_missing', 'Start the domain connection again.', 409);
        $token = (string)($claim['data']['token'] ?? '');
        if (!$this->txtVerified($domain, $token)) throw new Failure('domain_dns_pending', 'Add the TXT record shown in your account, then try again.', 409);
        $this->cloud->aliasAdd($a['domain'], $domain);
        $this->cloud->setCanonicalizeAliases($a['domain'], false);
        $a['custom_domain_status'] = 'pending_https'; $this->identity->save($a);
        $release = (string)($a['release_id'] ?? '');
        if ($release === '' || !$this->agent->publicHealthFor($domain, $release)) throw new Failure('domain_https_pending', 'DNS is verified. We are waiting for HTTPS to finish provisioning.', 409);
        $a['custom_domain_status'] = 'ready'; $a['public_domain'] = $domain; $this->identity->save($a);
        $claim['data']['verified_at'] = time(); $claim['data']['status'] = 'ready'; $this->store->put('custom_domain', $domain, $claim['data'], $owner, 'ready', 0);
        $this->store->audit($owner, 'custom_domain_connected', ['domain' => $domain]);
        return $this->status($owner);
    }

    public function remove(int $owner): array {
        $a = $this->identity->account($owner); $domain = (string)($a['custom_domain'] ?? '');
        if ($domain === '') return ['status' => 'none', 'domain' => null];
        $this->cloud->aliasRemove($a['domain'], $domain);
        $this->cloud->setCanonicalizeAliases($a['domain'], true);
        $this->store->remove('custom_domain', $domain);
        unset($a['custom_domain'], $a['custom_domain_status'], $a['custom_domain_token'], $a['public_domain']);
        $this->identity->save($a); $this->store->audit($owner, 'custom_domain_removed', ['domain' => $domain]);
        return ['status' => 'none', 'domain' => null];
    }

    private function txtVerified(string $domain, string $token): bool {
        $records = function_exists('dns_get_record') ? @dns_get_record('_dashless.' . $domain, DNS_TXT) : [];
        foreach ((array)$records as $record) if (hash_equals('dashless-verification=' . $token, trim((string)($record['txt'] ?? '')))) return true;
        return false;
    }

    public static function normalize(string $input): string {
        $value = strtolower(trim($input));
        if (str_contains($value, '://')) $value = (string)parse_url($value, PHP_URL_HOST);
        $value = rtrim($value, '.');
        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value)) throw new Failure('domain_invalid', 'Enter a domain such as example.com.', 400);
        return $value;
    }
}

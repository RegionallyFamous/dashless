<?php
namespace Dashless\Hub;
/** Versioned HTTP boundary implemented by the separate site-plugin workstream. */
class Agent {
    public function call(array $a,string $method,string $route,array $data=[]): array {
        if (empty($a['domain']) || $a['domain']!==Identity::slug($a['slug']).'.'.Config::domain()) throw new Failure('site_invalid','Site routing is invalid.',403);
        if (!preg_match('~^/[a-z0-9/_-]+$~',$route)) throw new Failure('route_invalid','Invalid site operation.');
        $args=['method'=>$method,'timeout'=>10,'redirection'=>0,'headers'=>[
            'Authorization'=>'Bearer '.Crypto::open($a['site_secret']), 'Content-Type'=>'application/json',
            'X-Dashless-Contract'=>'1','Cache-Control'=>'no-store',
        ]];
        if ($method!=='GET') $args['body']=wp_json_encode($data);
        $response=wp_remote_request('https://'.$a['domain'].'/wp-json/dashless-hosted/v1'.$route,$args);
        if (is_wp_error($response)) throw new Failure('site_unavailable','Your site did not respond. Try again shortly.',503);
        $code=wp_remote_retrieve_response_code($response);$body=json_decode(wp_remote_retrieve_body($response),true);
        if ($code<200 || $code>=300 || !is_array($body)) throw new Failure('site_operation_failed','Your site could not complete this operation (HTTP '.$code.').',503);
        if (($body['contract_version']??null)!==1 || (int)($body['site_id']??0)!==(int)$a['site_id']) throw new Failure('site_contract_mismatch','The site plugin needs a compatible update.',409);
        return $body;
    }
    public function publicHealth(array $a,string $release): bool {
        return $this->publicHealthFor((string)$a['domain'], $release);
    }
    public function publicHealthFor(string $domain,string $release): bool {
        $domain=Domains::normalize($domain);
        $r=wp_remote_get('https://'.$domain.'/?dashless_verify='.rawurlencode($release),['timeout'=>10,'redirection'=>0,'headers'=>['Cache-Control'=>'no-cache']]);
        return !is_wp_error($r) && wp_remote_retrieve_response_code($r)===200 && hash_equals($release,(string)wp_remote_retrieve_header($r,'x-dashless-release'));
    }
}

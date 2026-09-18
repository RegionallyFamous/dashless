<?php
namespace Dashless\Site;
if (!defined('ABSPATH')) { exit; }

final class Failure extends \RuntimeException {
    public function __construct(public string $slug, string $message, public int $status=409) { parent::__construct(Support::friendly($slug,$message)); }
}

final class Support {
    public const VERSION = '0.1.0';
    public const QUOTAS = ['queued_jobs'=>10,'active_jobs'=>1,'previews'=>5,'upload_bytes'=>20*1024*1024,'private_storage_bytes'=>512*1024*1024,'export_bytes'=>1024*1024*1024,'retained_jobs'=>100];
    /** Stable machine codes stay separate from the words readers and writers see. */
    public static function friendly(string $code,string $fallback): string {
        return [
            'unauthorized'=>'Please reconnect your blog and try again.',
            'wrong_account'=>'This blog belongs to a different account.',
            'invalid_actor'=>'Please sign in to your account and try again.',
            'not_bootstrapped'=>'Your blog is still being set up. Please try again shortly.',
            'invalid_arguments'=>'We could not understand that request. Please try again.',
            'runtime_unavailable'=>'Your blog is not ready to prepare previews yet. Please try again shortly.',
            'runtime_platform'=>'This hosting setup cannot prepare your blog yet. Please contact support.',
            'package_invalid'=>'A required file could not be verified. Your live blog has not changed. Please contact support.',
            'artifact_invalid'=>'We could not safely open this file. Please create a new preview or download.',
            'artifact_missing'=>'This page or file is no longer available. Please open it again from your account.',
            'manifest_invalid'=>'We could not safely prepare these changes. Your live blog has not changed. Please try again.',
            'snapshot_changed'=>'Your content changed while the preview was being prepared. Please create a new preview.',
            'stale_approval'=>'Something changed after you reviewed this preview. Please preview and approve the latest version.',
            'stale_change'=>'This story changed since you started editing. Please reopen the latest version.',
            'stage_required'=>'Please prepare and preview your changes before updating a published story.',
            'invalid_idempotency_key'=>'We could not safely save this request. Please try again.',
            'idempotency_conflict'=>'This request changed after it was saved. Please start a new request.',
            'job_conflict'=>'This request is already saved. Please check its progress.',
            'operation_busy'=>'Another change is being saved. Please try again in a moment.',
            'build_deadline'=>'This is taking too long. Your live blog has not changed. Please try again.',
            'build_memory_limit'=>'We could not finish preparing your blog. Your live blog has not changed. Please contact support.',
            'parent_lost'=>'Preparing your blog was interrupted. Your saved work is safe. Please try again.',
            'build_failed'=>'We could not prepare these changes. Your live blog has not changed. Please try again.',
            'job_failed'=>'We could not finish this request. Your saved work is safe. Please try again.',
            'frontend_quality_failed'=>'The preview did not pass our checks. Your live blog has not changed. Please try again.',
            'public_verification_failed'=>'Your changes were saved, but we could not confirm they are live. Your previous blog has been kept online.',
            'content_changed_after_save'=>'Your story was saved, but changed again before it went live. Please create a new preview.',
            'rollback_missing'=>'That earlier version is no longer available to restore.',
            'ticket_expired'=>'This link has expired or was already used. Please open a fresh link from your account.',
            'term_missing'=>'A selected category or tag no longer exists. Please choose it again.',
            'term_failed'=>'We could not save that category or tag. Please try again.',
            'media_source_invalid'=>'An image or file is missing from your library. Please upload it again.',
            'media_copy_failed'=>'We could not prepare an image or file. Please try again.',
            'export_snapshot_changed'=>'Your blog changed while the download was being prepared. Please request a new download.',
            'export_asset_changed'=>'An image or file changed while the download was being prepared. Please request a new download.',
            'export_source_invalid'=>'We could not read an image or file. Please request a new download.',
            'export_source_missing'=>'An image or file is missing. Please check your library and try again.',
            'export_pending'=>'Your download is still being prepared. Please check back shortly.',
            'retention_expired'=>'The 30-day recovery period has ended. Please contact support.',
            'capacity_limited'=>'Your blog has reached its current work limit. Please wait for the saved request to finish.',
            'storage_quota'=>'Your private media storage is full. Remove an upload or wait for cleanup before adding another file.',
            'export_quota'=>'This download exceeds the supported export size.',
        ][$code]??$fallback;
    }
    public static function uuid(string $id): string {
        if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $id)) { throw new Failure('invalid_id','A valid identifier is required.',400); }
        return $id;
    }
    public static function canonical(mixed $value): mixed {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        return array_map([self::class,'canonical'],$value);
    }
    public static function hash(mixed $value): string { return hash('sha256',wp_json_encode(self::canonical($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); }
    public static function json(string $file): array {
        $data=json_decode((string)file_get_contents($file),true);
        if (!is_array($data)) { throw new Failure('artifact_invalid','A stored artifact failed validation.',503); }
        return $data;
    }
    public static function write(string $file, string $bytes): void {
        if (!is_dir(dirname($file)) && !wp_mkdir_p(dirname($file))) { throw new Failure('storage_failed','Private storage is unavailable.',503); }
        $tmp=$file.'.'.bin2hex(random_bytes(8)).'.tmp';
        $h=fopen($tmp,'xb');
        if (!$h) { throw new Failure('storage_failed','Private storage is unavailable.',503); }
        chmod($tmp,0600);
        try { if (fwrite($h,$bytes)!==strlen($bytes)) { throw new Failure('storage_failed','Private storage is full.',503); } fflush($h); }
        finally { fclose($h); }
        if (!rename($tmp,$file)) { @unlink($tmp); throw new Failure('storage_failed','Could not save the artifact.',503); }
    }
    public static function relative(string $path): string {
        if ($path==='' || str_starts_with($path,'/') || preg_match('/[\\\\\x00-\x1f\x7f]/',$path) || array_intersect(explode('/',$path),['','..','.'])) { throw new Failure('invalid_path','The requested artifact was not found.',404); }
        return $path;
    }
    public static function origin(string $url): string {
        $p=wp_parse_url($url);
        if (!$p || ($p['scheme']??'')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || !in_array($p['path']??'',['','/'],true)) { throw new Failure('invalid_origin','A secure origin is required.',400); }
        return 'https://'.strtolower($p['host']).(isset($p['port'])?':'.$p['port']:'');
    }
    public static function purge(): void {
        wp_cache_flush();
        // WP Cloud's Batcache integration observes this standard invalidation hook.
        do_action('wp_cache_cleared');
        if (function_exists('wpcom_vip_cache_purge_site')) { wpcom_vip_cache_purge_site(); }
        if (function_exists('wpcom_cache_flush')) { wpcom_cache_flush(); }
    }
    public static function noCache(): array {
        return ['Cache-Control'=>'private, no-store, max-age=0','Pragma'=>'no-cache','Expires'=>'0','Vary'=>'Authorization, Cookie','Referrer-Policy'=>'no-referrer','X-Content-Type-Options'=>'nosniff','X-Robots-Tag'=>'noindex, nofollow'];
    }
}

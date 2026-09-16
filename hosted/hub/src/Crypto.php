<?php
namespace Dashless\Hub;
final class Crypto {
    private static function key(): string {
        $key=base64_decode(Config::required('encryption_key'),true);
        if ($key===false || strlen($key)!==32) throw new Failure('encryption_unconfigured','Secure storage is not configured.',503);
        return $key;
    }
    public static function seal(string $value): string {
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox($value,$nonce,self::key()));
    }
    public static function open(string $value): string {
        $raw=base64_decode($value,true);
        if ($raw===false || strlen($raw)<40) throw new Failure('invalid_secret','Secure storage could not be read.',503);
        $plain=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),self::key());
        if ($plain===false) throw new Failure('invalid_secret','Secure storage could not be read.',503);
        return $plain;
    }
}

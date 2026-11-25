<?php

declare(strict_types=1);

namespace Elevate\Tests;

final class Keys
{
    public static function rsaPrivateKey(): string
    {
        return self::export(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    }

    public static function ecPrivateKey(string $curve = 'prime256v1'): string
    {
        return self::export(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
    }

    public static function publicKey(string $privateKey): string
    {
        $key = openssl_pkey_get_private($privateKey);
        \assert(false !== $key);
        $details = openssl_pkey_get_details($key);
        \assert(false !== $details);

        $pem = $details['key'];
        \assert(\is_string($pem));

        return $pem;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function export(array $options): string
    {
        $key = openssl_pkey_new($options);
        \assert(false !== $key);
        openssl_pkey_export($key, $pem);
        \assert(\is_string($pem));

        return $pem;
    }
}

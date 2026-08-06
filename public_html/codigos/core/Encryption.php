<?php
require_once __DIR__ . '/../config/init.php';

class Encryption
{
    private const METHOD = 'aes-256-cbc';

    private static function key(): string
    {
        // Deriva una clave de 32 bytes estable a partir de ENCRYPTION_KEY
        return hash('sha256', ENCRYPTION_KEY, true);
    }

    public static function encrypt(string $plain): string
    {
        $ivLen = openssl_cipher_iv_length(self::METHOD);
        $iv = openssl_random_pseudo_bytes($ivLen);
        $cipherText = openssl_encrypt($plain, self::METHOD, self::key(), OPENSSL_RAW_DATA, $iv);
        // Guardamos iv + cipherText juntos, codificados en base64
        return base64_encode($iv . $cipherText);
    }

    public static function decrypt(string $encoded): string
    {
        $data = base64_decode($encoded);
        $ivLen = openssl_cipher_iv_length(self::METHOD);
        $iv = substr($data, 0, $ivLen);
        $cipherText = substr($data, $ivLen);
        $plain = openssl_decrypt($cipherText, self::METHOD, self::key(), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }
}

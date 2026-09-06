<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Symmetric encryption (AES-256-GCM) and signed tokens using the app key.
 */
final class Crypto
{
    private static function key(): string
    {
        $key = (string) App::config('app.key', '');
        if (strlen($key) < 16) {
            throw new \RuntimeException('APP key is not configured.');
        }
        return hash('sha256', $key, true);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        if ($payload === '' || !str_starts_with($payload, 'enc:v1:')) {
            return $payload;
        }
        $raw = base64_decode(substr($payload, 7), true);
        if ($raw === false || strlen($raw) < 28) {
            return '';
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    public static function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, self::key());
    }

    /** Create a URL-safe signed token carrying a JSON payload. */
    public static function sign(array $payload, int $ttlSeconds = 0): string
    {
        if ($ttlSeconds > 0) {
            $payload['exp'] = time() + $ttlSeconds;
        }
        $data = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}');
        return $data . '.' . self::b64(hash_hmac('sha256', $data, self::key(), true));
    }

    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$data, $sig] = $parts;
        $expected = self::b64(hash_hmac('sha256', $data, self::key(), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $payload = json_decode(self::unb64($data), true);
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }

    public static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function unb64(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}

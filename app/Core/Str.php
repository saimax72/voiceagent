<?php
declare(strict_types=1);

namespace App\Core;

final class Str
{
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** 32-char hex identifier used for public agent/conversation ids. */
    public static function publicId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function random(int $length = 32): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    public static function slug(string $value, string $separator = '-'): string
    {
        $value = strtolower(trim($value));
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }
        $value = preg_replace('/[^a-z0-9]+/', $separator, $value) ?? '';
        return trim($value, $separator) ?: 'item';
    }

    /** Rough token estimate (chars / 4). */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    public static function normalizeWhitespace(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/[ ]*\n[ ]*/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    public static function maskSecret(string $secret): string
    {
        $len = strlen($secret);
        if ($len === 0) {
            return '';
        }
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($secret, 0, 4) . str_repeat('*', min(20, $len - 8)) . substr($secret, -4);
    }

    /** Extract the host (without www.) from a URL. */
    public static function host(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return preg_replace('/^www\./', '', $host) ?? $host;
    }
}

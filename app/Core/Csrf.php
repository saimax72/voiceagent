<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (!Session::isStarted()) {
            Session::start();
        }
        $token = Session::get('_csrf');
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            Session::set('_csrf', $token);
        }
        return $token;
    }

    public static function verify(Request $request): bool
    {
        $expected = Session::get('_csrf');
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        $given = $request->input('_token') ?? $request->header('X-CSRF-Token') ?? '';
        if (!is_string($given) || $given === '') {
            return false;
        }
        return hash_equals($expected, $given);
    }
}

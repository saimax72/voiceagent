<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    private static bool $started = false;
    private static array $flash = [];
    private static array $oldInput = [];

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli') {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }
        $request = App::request();
        session_name((string) (App::config('app.session_name') ?: 'va_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => App::basePath() ?: '/',
            'domain' => '',
            'secure' => $request->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '86400');
        session_start();
        self::$started = true;

        // Consume flash data set by the previous request
        self::$flash = $_SESSION['_flash_next'] ?? [];
        self::$oldInput = $_SESSION['_old_input_next'] ?? [];
        unset($_SESSION['_flash_next'], $_SESSION['_old_input_next']);
        $_SESSION['_old_input'] = self::$oldInput;
    }

    public static function isStarted(): bool
    {
        return self::$started;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$started) {
            return $default;
        }
        if ($key === '_flash_messages') {
            return $_SESSION['_flash_next'] ?? $default;
        }
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        if (!self::$started) {
            return;
        }
        if ($key === '_flash_messages') {
            $_SESSION['_flash_next'] = $value;
            return;
        }
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return self::$started && isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        if (self::$started) {
            unset($_SESSION[$key]);
        }
    }

    /** Flash messages set during the previous request. */
    public static function flashMessages(): array
    {
        return self::$flash;
    }

    public static function setOldInput(array $input): void
    {
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        if (self::$started) {
            $_SESSION['_old_input_next'] = $input;
        }
    }

    public static function oldInput(): array
    {
        return self::$oldInput;
    }

    public static function regenerate(): void
    {
        if (self::$started) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (!self::$started) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        self::$started = false;
    }
}

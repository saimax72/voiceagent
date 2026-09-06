<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Application container: configuration, lifecycle, error handling.
 */
final class App
{
    private static array $config = [];
    private static ?Request $request = null;
    private static ?string $baseUrl = null;

    public static function init(array $config): void
    {
        self::$config = $config;
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');
        error_reporting(E_ALL);
        ini_set('display_errors', self::isDebug() ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', APP_ROOT . '/storage/logs/php-errors.log');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                Logger::error('Fatal error: ' . $error['message'], ['file' => $error['file'], 'line' => $error['line']]);
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    http_response_code(500);
                    echo self::isDebug()
                        ? '<pre>' . htmlspecialchars($error['message'] . ' in ' . $error['file'] . ':' . $error['line']) . '</pre>'
                        : 'An unexpected error occurred.';
                }
            }
        });
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$config;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function allConfig(): array
    {
        return self::$config;
    }

    public static function isDebug(): bool
    {
        return (bool) (self::$config['app']['debug'] ?? false);
    }

    public static function request(): Request
    {
        if (self::$request === null) {
            self::$request = Request::capture();
        }
        return self::$request;
    }

    /** Path prefix when the app is installed in a sub-directory (e.g. "/voiceagent"). */
    public static function basePath(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $dir = str_replace('\\', '/', dirname($script));
        return rtrim($dir, '/');
    }

    public static function baseUrl(): string
    {
        if (self::$baseUrl !== null) {
            return self::$baseUrl;
        }
        $configured = trim((string) (self::$config['app']['url'] ?? ''));
        if ($configured !== '' && preg_match('~^https?://~i', $configured)) {
            return self::$baseUrl = rtrim($configured, '/');
        }
        if (PHP_SAPI === 'cli') {
            return self::$baseUrl = '';
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        return self::$baseUrl = ($https ? 'https' : 'http') . '://' . $host . self::basePath();
    }

    /** Run the HTTP kernel. */
    public static function run(): void
    {
        $request = self::request();
        $path = $request->path();

        // Stateless endpoints never start a session (public widget API, cron, webhooks)
        $stateless = str_starts_with($path, '/api/widget') || str_starts_with($path, '/webcron') || str_starts_with($path, '/webhooks');
        if (!$stateless) {
            Session::start();
        }

        $router = new Router();
        require APP_PATH . '/routes.php';

        try {
            $response = $router->dispatch($request);
        } catch (HttpException $e) {
            $response = self::errorResponse($e->status, $e->getMessage(), $request);
        } catch (\Throwable $e) {
            Logger::exception($e);
            $message = self::isDebug()
                ? get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString()
                : '';
            $response = self::errorResponse(500, $message, $request);
        }

        $response->send();
    }

    public static function handleException(\Throwable $e): void
    {
        Logger::exception($e);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n");
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo self::isDebug()
            ? '<pre>' . htmlspecialchars(get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>'
            : 'An unexpected error occurred.';
    }

    public static function errorResponse(int $status, string $message, ?Request $request = null): Response
    {
        $request ??= self::request();
        $titles = [
            400 => 'Bad request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Page not found',
            405 => 'Method not allowed', 419 => 'Session expired', 422 => 'Validation failed',
            429 => 'Too many requests', 500 => 'Server error', 503 => 'Service unavailable',
        ];
        $title = $titles[$status] ?? 'Error';
        if ($request->wantsJson()) {
            return Response::json(['error' => $message !== '' ? $message : $title, 'status' => $status], $status);
        }
        try {
            $html = View::render('errors/error', ['status' => $status, 'title' => $title, 'message' => $message], 'layouts/minimal');
        } catch (\Throwable) {
            $html = '<!doctype html><title>' . $status . ' ' . htmlspecialchars($title) . '</title><h1>' . $status . ' '
                . htmlspecialchars($title) . '</h1><p>' . nl2br(htmlspecialchars($message)) . '</p>';
        }
        return Response::html($html, $status);
    }

    /** Languages the assistant can be configured to speak. */
    public static function languages(): array
    {
        return [
            'auto' => 'Auto-detect (match the visitor)',
            'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German', 'it' => 'Italian',
            'pt' => 'Portuguese', 'nl' => 'Dutch', 'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian',
            'fi' => 'Finnish', 'pl' => 'Polish', 'cs' => 'Czech', 'ro' => 'Romanian', 'hu' => 'Hungarian',
            'el' => 'Greek', 'tr' => 'Turkish', 'ru' => 'Russian', 'uk' => 'Ukrainian', 'ar' => 'Arabic',
            'he' => 'Hebrew', 'hi' => 'Hindi', 'bn' => 'Bengali', 'ur' => 'Urdu', 'id' => 'Indonesian',
            'ms' => 'Malay', 'th' => 'Thai', 'vi' => 'Vietnamese', 'zh' => 'Chinese', 'ja' => 'Japanese',
            'ko' => 'Korean', 'tl' => 'Filipino', 'sw' => 'Swahili', 'af' => 'Afrikaans',
        ];
    }
}

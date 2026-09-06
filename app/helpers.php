<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Settings;

/** HTML-escape a value for safe output. */
function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Read a config value with dot notation, e.g. config('db.host'). */
function config(string $key, mixed $default = null): mixed
{
    return App::config($key, $default);
}

/** Application base URL (no trailing slash). */
function base_url(): string
{
    return App::baseUrl();
}

/** Build an absolute URL to an application route. */
function url(string $path = '', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $url = base_url() . ($path === '/' ? '' : $path);
    if ($query) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
    return $url === '' ? '/' : $url;
}

/** URL for a static asset, cache-busted by app version. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    return base_url() . '/' . $path . '?v=' . APP_VERSION;
}

function redirect(string $to, int $status = 302): Response
{
    if (!preg_match('~^https?://~i', $to)) {
        $to = url($to);
    }
    return Response::redirect($to, $status);
}

function back(): Response
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $base = base_url();
    if ($ref && ($base === '' || str_starts_with($ref, $base) || str_starts_with($ref, '/'))) {
        return Response::redirect($ref);
    }
    return redirect('/dashboard');
}

function json(mixed $data, int $status = 200, array $headers = []): Response
{
    return Response::json($data, $status, $headers);
}

function view(string $template, array $data = [], ?string $layout = null): Response
{
    return Response::html(View::render($template, $data, $layout));
}

function abort(int $status, string $message = ''): never
{
    throw new \App\Core\HttpException($status, $message);
}

function db(): DB
{
    return DB::instance();
}

function auth(): Auth
{
    return Auth::instance();
}

function current_user(): ?array
{
    return Auth::instance()->user();
}

function current_tenant(): ?array
{
    return Auth::instance()->tenant();
}

function tenant_id(): int
{
    return (int) (Auth::instance()->tenant()['id'] ?? 0);
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

function old(string $key, mixed $default = ''): mixed
{
    $old = Session::get('_old_input', []);
    return $old[$key] ?? $default;
}

function flash(string $type, string $message): void
{
    $messages = Session::get('_flash_messages', []);
    $messages[] = ['type' => $type, 'message' => $message];
    Session::set('_flash_messages', $messages);
}

function setting(string $key, mixed $default = null): mixed
{
    return Settings::get($key, $default);
}

function app_name(): string
{
    return (string) (Settings::get('app_name') ?: config('app.name', 'VoiceAgent'));
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Decode a JSON column safely. */
function json_field(mixed $value, array $default = []): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || $value === '') {
        return $default;
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : $default;
}

function json_encode_pretty(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
}

function str_limit(string $value, int $limit = 100, string $end = '...'): string
{
    if (mb_strlen($value) <= $limit) {
        return $value;
    }
    return rtrim(mb_substr($value, 0, $limit)) . $end;
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime($datetime . ' UTC');
    if ($ts === false) {
        return $datetime;
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'just now';
    }
    $units = [31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];
    foreach ($units as $secs => $name) {
        if ($diff >= $secs) {
            $n = (int) floor($diff / $secs);
            return $n . ' ' . $name . ($n > 1 ? 's' : '') . ' ago';
        }
    }
    return 'just now';
}

function format_date(?string $datetime, string $format = 'M j, Y'): string
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime($datetime . ' UTC');
    if ($ts === false) {
        return $datetime;
    }
    $tz = current_user()['timezone'] ?? config('app.timezone', 'UTC');
    try {
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone(new DateTimeZone($tz ?: 'UTC'));
        return $dt->format($format);
    } catch (\Throwable) {
        return date($format, $ts);
    }
}

function format_number(int|float $n, int $decimals = 0): string
{
    return number_format($n, $decimals);
}

function human_filesize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $size = (float) $bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

function route_is(string $prefix): bool
{
    $path = App::request()->path();
    return $path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/');
}

function log_error(string $message, array $context = []): void
{
    \App\Core\Logger::error($message, $context);
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $initials .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $initials ?: 'A';
}

function array_only(array $array, array $keys): array
{
    return array_intersect_key($array, array_flip($keys));
}

function clamp(int|float $value, int|float $min, int|float $max): int|float
{
    return max($min, min($max, $value));
}

/** Pick a language name from its code. */
function language_name(string $code): string
{
    $langs = App::languages();
    return $langs[$code] ?? $code;
}

<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private ?array $json = null;
    private ?string $raw = null;

    public function __construct(
        private string $method,
        private string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $files,
        public readonly array $cookies,
        public readonly array $server,
    ) {
    }

    public static function capture(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $base = App::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim(rawurldecode($path), '/');
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }
        return new self($method, $path, $_GET, $_POST, $_FILES, $_COOKIE, $_SERVER);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function uri(): string
    {
        return $this->server['REQUEST_URI'] ?? '/';
    }

    public function fullUrl(): string
    {
        return App::baseUrl() . $this->path . ($this->query ? '?' . http_build_query($this->query) : '');
    }

    public function raw(): string
    {
        if ($this->raw === null) {
            $this->raw = (string) file_get_contents('php://input');
        }
        return $this->raw;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('Content-Type') ?? ''), 'application/json');
    }

    public function json(): array
    {
        if ($this->json === null) {
            $decoded = json_decode($this->raw(), true);
            $this->json = is_array($decoded) ? $decoded : [];
        }
        return $this->json;
    }

    /** All input: JSON body (if any) merged over form body merged over query. */
    public function all(): array
    {
        $data = array_merge($this->query, $this->body);
        if ($this->isJson()) {
            $data = array_merge($data, $this->json());
        }
        return $data;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if ($this->isJson()) {
            $json = $this->json();
            if (array_key_exists($key, $json)) {
                return $json[$key];
            }
        }
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }
        return $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        if (is_array($value) || $value === null) {
            return $default;
        }
        return trim((string) $value);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->input($key);
        return is_numeric($value) ? (float) $value : $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key);
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    public function array(string $key): array
    {
        $value = $this->input($key);
        return is_array($value) ? $value : [];
    }

    public function only(array $keys): array
    {
        $all = $this->all();
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $all)) {
                $out[$k] = $all[$k];
            }
        }
        return $out;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!$file || !isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $file;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$key])) {
            return $this->server[$key];
        }
        $lower = strtolower($name);
        if ($lower === 'content-type') {
            return $this->server['CONTENT_TYPE'] ?? null;
        }
        if ($lower === 'content-length') {
            return $this->server['CONTENT_LENGTH'] ?? null;
        }
        return null;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization') ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    public function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($this->server[$key])) {
                $ip = trim(explode(',', (string) $this->server[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public function origin(): ?string
    {
        return $this->server['HTTP_ORIGIN'] ?? null;
    }

    public function referer(): ?string
    {
        return $this->server['HTTP_REFERER'] ?? null;
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('X-Requested-With') ?? '') === 'xmlhttprequest';
    }

    public function wantsJson(): bool
    {
        if (str_starts_with($this->path, '/api/')) {
            return true;
        }
        $accept = $this->header('Accept') ?? '';
        return $this->isAjax() || (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html'));
    }

    public function isSecure(): bool
    {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off')
            || (($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}

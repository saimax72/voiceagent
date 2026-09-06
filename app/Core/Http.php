<?php
declare(strict_types=1);

namespace App\Core;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $error = '',
        public readonly string $url = '',
        public readonly float $duration = 0.0,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === '' && $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function contentType(): string
    {
        return strtolower(trim(explode(';', $this->header('content-type') ?? '')[0]));
    }

    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }
}

/**
 * cURL-based HTTP client with streaming support and SSRF protection.
 *
 * Options: headers (assoc), json (array), form (array), body (string), multipart (array; use CURLFile for files),
 *          timeout, connect_timeout, follow (bool), max_redirects, max_bytes, user_agent,
 *          stream (callable(string $chunk): bool), public_only (bool: block private/local IPs)
 */
final class Http
{
    public static function get(string $url, array $options = []): HttpResponse
    {
        return self::request('GET', $url, $options);
    }

    public static function post(string $url, array $options = []): HttpResponse
    {
        return self::request('POST', $url, $options);
    }

    public static function postJson(string $url, array $data, array $headers = [], array $options = []): HttpResponse
    {
        $options['json'] = $data;
        $options['headers'] = array_merge($options['headers'] ?? [], $headers);
        return self::request('POST', $url, $options);
    }

    public static function request(string $method, string $url, array $options = []): HttpResponse
    {
        $redirects = 0;
        $maxRedirects = (int) ($options['max_redirects'] ?? 5);
        $follow = $options['follow'] ?? true;
        $start = microtime(true);

        while (true) {
            if (!empty($options['public_only']) && ($reason = self::blockedUrlReason($url)) !== null) {
                return new HttpResponse(0, [], '', $reason, $url, microtime(true) - $start);
            }
            $response = self::execute($method, $url, $options);
            if ($follow && in_array($response->status, [301, 302, 303, 307, 308], true) && $redirects < $maxRedirects) {
                $location = $response->header('location');
                if ($location) {
                    $url = self::resolveUrl($url, $location);
                    if (in_array($response->status, [301, 302, 303], true) && $method !== 'GET' && $method !== 'HEAD') {
                        $method = 'GET';
                        unset($options['json'], $options['form'], $options['body'], $options['multipart']);
                    }
                    $redirects++;
                    continue;
                }
            }
            return new HttpResponse($response->status, $response->headers, $response->body, $response->error, $url, microtime(true) - $start);
        }
    }

    private static function execute(string $method, string $url, array $options): HttpResponse
    {
        $ch = curl_init();
        $headers = [];
        foreach (($options['headers'] ?? []) as $k => $v) {
            $headers[] = is_int($k) ? $v : $k . ': ' . $v;
        }
        $body = null;
        if (isset($options['json'])) {
            $body = json_encode($options['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        } elseif (isset($options['form'])) {
            $body = http_build_query($options['form']);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif (isset($options['multipart'])) {
            $body = $options['multipart'];
        } elseif (isset($options['body'])) {
            $body = (string) $options['body'];
        }

        $responseHeaders = [];
        $buffer = '';
        $received = 0;
        $maxBytes = (int) ($options['max_bytes'] ?? 0);
        $stream = $options['stream'] ?? null;
        $statusHolder = ['status' => 0];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int) ($options['connect_timeout'] ?? 10),
            CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? 30),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $options['user_agent'] ?? 'VoiceAgent/' . APP_VERSION,
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders, &$statusHolder): int {
                if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m)) {
                    $statusHolder['status'] = (int) $m[1];
                    $responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$received, $maxBytes, $stream, &$statusHolder): int {
                $received += strlen($chunk);
                if ($maxBytes > 0 && $received > $maxBytes) {
                    $buffer .= $chunk;
                    return -1; // abort: too large
                }
                if ($stream !== null && $statusHolder['status'] >= 200 && $statusHolder['status'] < 300) {
                    $continue = $stream($chunk);
                    if ($continue === false) {
                        return -1;
                    }
                    return strlen($chunk);
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        if (!empty($options['basic_auth'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $options['basic_auth']);
        }

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = '';
        if ($errno !== 0) {
            $error = $errno === CURLE_WRITE_ERROR
                ? ($maxBytes > 0 && $received > $maxBytes ? 'Response too large' : 'Stream aborted')
                : curl_error($ch);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: $statusHolder['status'];
        curl_close($ch);

        // Aborting due to size is not an error for the caller when we still have partial content
        if ($error === 'Response too large' && $buffer !== '') {
            $error = '';
            $responseHeaders['x-truncated'] = '1';
        }
        if ($error === 'Stream aborted') {
            $error = '';
        }
        return new HttpResponse($status, $responseHeaders, $buffer, $error, $url);
    }

    /** Returns a reason string when the URL must not be fetched (private network / invalid). */
    public static function blockedUrlReason(string $url): ?string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return 'Invalid URL';
        }
        $host = strtolower($parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'Local addresses are not allowed';
        }
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
            foreach ($records as $r) {
                if (!empty($r['ip'])) {
                    $ips[] = $r['ip'];
                }
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
            if (!$ips) {
                $resolved = gethostbyname($host);
                if ($resolved !== $host) {
                    $ips[] = $resolved;
                }
            }
            if (!$ips) {
                return 'Could not resolve host';
            }
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'Private network addresses are not allowed';
            }
        }
        return null;
    }

    public static function resolveUrl(string $base, string $relative): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $relative)) {
            return $relative;
        }
        $b = parse_url($base);
        if (!$b || empty($b['host'])) {
            return $relative;
        }
        $scheme = $b['scheme'] ?? 'https';
        $origin = $scheme . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($relative, '//')) {
            return $scheme . ':' . $relative;
        }
        if (str_starts_with($relative, '/')) {
            return $origin . $relative;
        }
        if (str_starts_with($relative, '?')) {
            return $origin . ($b['path'] ?? '/') . $relative;
        }
        if (str_starts_with($relative, '#') || $relative === '') {
            return $base;
        }
        $path = $b['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1);
        $full = $dir . $relative;
        // Normalise ./ and ../
        $segments = [];
        foreach (explode('/', $full) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $seg;
        }
        return $origin . '/' . implode('/', $segments);
    }
}

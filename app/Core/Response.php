<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @param string|\Closure $body */
    public function __construct(
        private mixed $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        return new self($html, $status, array_merge(['Content-Type' => 'text/html; charset=UTF-8'], $headers));
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            $body = json_encode(['error' => 'Failed to encode response']);
            $status = 500;
        }
        return new self($body, $status, array_merge(['Content-Type' => 'application/json; charset=UTF-8'], $headers));
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public static function noContent(int $status = 204, array $headers = []): self
    {
        return new self('', $status, $headers);
    }

    /** Streaming response: the closure writes output itself (SSE, audio). */
    public static function stream(\Closure $callback, array $headers = [], int $status = 200): self
    {
        return new self($callback, $status, $headers);
    }

    public static function file(string $path, ?string $downloadName = null, ?string $mime = null): self
    {
        if (!is_file($path)) {
            throw new HttpException(404, 'File not found');
        }
        $mime ??= (mime_content_type($path) ?: 'application/octet-stream');
        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string) filesize($path),
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($downloadName !== null) {
            $headers['Content-Disposition'] = 'attachment; filename="' . addslashes($downloadName) . '"';
        }
        return new self(static function () use ($path): void {
            $fh = fopen($path, 'rb');
            if ($fh) {
                fpassthru($fh);
                fclose($fh);
            }
        }, 200, $headers);
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function body(): mixed
    {
        return $this->body;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if ($this->body instanceof \Closure) {
            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }
            @ini_set('zlib.output_compression', '0');
            @ini_set('implicit_flush', '1');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ob_implicit_flush(true);
            ($this->body)();
            return;
        }
        echo $this->body;
    }
}

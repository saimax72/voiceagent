<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal PHP template renderer with layouts and named sections.
 */
final class View
{
    private static array $sections = [];
    private static array $stack = [];

    public static function render(string $template, array $data = [], ?string $layout = null): string
    {
        $data['app_name'] = $data['app_name'] ?? app_name();
        $content = self::renderFile($template, $data);
        if ($layout === null || $layout === '') {
            return $content;
        }
        $data['content'] = $content;
        return self::renderFile($layout, $data);
    }

    public static function partial(string $template, array $data = []): string
    {
        return self::renderFile($template, $data);
    }

    public static function renderFile(string $template, array $data): string
    {
        $safe = str_replace(['..', '\\'], ['', '/'], $template);
        $file = APP_PATH . '/Views/' . $safe . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    /** Start capturing a named section (e.g. scripts, head). */
    public static function start(string $name): void
    {
        self::$stack[] = $name;
        ob_start();
    }

    public static function stop(): void
    {
        $name = array_pop(self::$stack);
        $content = (string) ob_get_clean();
        if ($name === null) {
            return;
        }
        self::$sections[$name] = (self::$sections[$name] ?? '') . $content;
    }

    public static function section(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }
}

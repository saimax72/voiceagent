<?php
declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $dir = APP_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . strtoupper($level) . ': ' . $message;
        if ($context) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        if (App::isDebug()) {
            self::log('debug', $message, $context);
        }
    }

    public static function exception(\Throwable $e): void
    {
        self::log('error', get_class($e) . ': ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }
}

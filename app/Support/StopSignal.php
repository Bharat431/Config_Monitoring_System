<?php

namespace App\Support;

/**
 * Cooperative graceful-shutdown signal.
 *
 * Uses pcntl where available (containers/Linux) and a stop-file fallback on
 * platforms without pcntl (Windows, shared hosting).
 */
final class StopSignal
{
    private static bool $requested = false;

    public static function install(): void
    {
        self::$requested = false;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static fn (): bool => self::request());
            pcntl_signal(SIGTERM, static fn (): bool => self::request());
        }
    }

    public static function request(): bool
    {
        self::$requested = true;

        return true;
    }

    public static function isRequested(): bool
    {
        if (self::$requested) {
            return true;
        }

        return is_file(self::stopFile());
    }

    public static function requestSoftly(): void
    {
        $path = self::stopFile();
        $dir  = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, gmdate('c'));
    }

    public static function clear(): void
    {
        self::$requested = false;
        @unlink(self::stopFile());
    }

    public static function stopFile(): string
    {
        return WRITEPATH . 'monitor/stop';
    }
}

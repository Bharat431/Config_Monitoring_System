<?php

namespace App\Support;

use CodeIgniter\CLI\CLI;

/**
 * Small compatibility layer over CodeIgniter's CLI option parser.
 *
 * CodeIgniter only understands `--name value`; operators habitually type
 * `--name=value`. This helper accepts both spellings and normalises boolean
 * flags so `--once=false` behaves as expected.
 */
final class CliOption
{
    public static function get(string $name): mixed
    {
        $value = CLI::getOption($name);
        if ($value !== null) {
            return $value;
        }

        foreach (CLI::getOptions() as $key => $candidate) {
            if (is_string($key) && str_starts_with($key, $name . '=')) {
                return substr($key, strlen($name) + 1);
            }
        }

        return null;
    }

    public static function flag(string $name): bool
    {
        $value = self::get($name);

        if ($value === null) {
            return false;
        }

        if ($value === true || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    public static function int(string $name, int $default = 0): int
    {
        $value = self::get($name);

        return is_numeric($value) ? (int) $value : $default;
    }
}

<?php

namespace App\Domain\Parsing;

abstract class AbstractParser implements ParserInterface
{
    protected function stripBom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }

    protected function assertMap(mixed $value, string $path): array
    {
        $label = $path !== '' ? basename($path) : 'the configuration';

        // null and non-array roots usually mean a file was truncated or only
        // partially written (e.g. "null", "true", a bare string). Treat them
        // as parse errors so the last good snapshot is kept instead of emitting
        // a bogus "all keys removed" diff.
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ConfigParseException(sprintf(
                'The root of %s must be an object/map, got %s.',
                $label,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}

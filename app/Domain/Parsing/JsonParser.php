<?php

namespace App\Domain\Parsing;

use JsonException;

class JsonParser extends AbstractParser
{
    public function name(): string
    {
        return 'json';
    }

    public function extensions(): array
    {
        return ['json'];
    }

    public function supports(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json';
    }

    public function parse(string $contents, string $path = ''): array
    {
        $contents = $this->stripBom($contents);

        if (trim($contents) === '') {
            throw new ConfigParseException('JSON configuration is empty.');
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $trimmed = rtrim($contents);
            $truncated = $trimmed !== '' && ! str_ends_with($trimmed, '}') && ! str_ends_with($trimmed, ']');
            $message = 'Invalid JSON: ' . $e->getMessage()
                . ($truncated ? ' (file may be partially written)' : '');

            throw new ConfigParseException(
                $message,
                null,
                $this->snippet($contents, $e->getCode()),
                $e,
            );
        }

        return $this->assertMap($decoded, $path);
    }

    private function snippet(string $contents, int $code): ?string
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        if ($lines === [] || $code <= 0) {
            return null;
        }

        // json_last_error_msg() does not expose a line, so keep the first chunk.
        return trim($lines[0]);
    }
}

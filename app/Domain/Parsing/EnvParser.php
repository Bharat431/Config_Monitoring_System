<?php

namespace App\Domain\Parsing;

/**
 * Parser for .env files.
 *
 * Supports:
 *   - comments (#) and blank lines
 *   - optional `export ` prefix
 *   - single/double quoted values, including multi-line values
 *   - inline comments in unquoted values
 *   - escape sequences in double quoted values
 */
class EnvParser extends AbstractParser
{
    public function name(): string
    {
        return 'env';
    }

    public function extensions(): array
    {
        return ['env'];
    }

    public function supports(string $path): bool
    {
        $base = strtolower(basename($path));

        return $base === '.env' || str_starts_with($base, '.env.');
    }

    public function parse(string $contents, string $path = ''): array
    {
        $contents = $this->stripBom($contents);
        if (trim($contents) === '') {
            throw new ConfigParseException('Dotenv configuration is empty.');
        }

        $lines    = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $count    = count($lines);
        $data     = [];

        for ($i = 0; $i < $count; $i++) {
            $lineNo  = $i + 1;
            $line    = $lines[$i];
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^export\s+(.+)$/', $trimmed, $matches) === 1) {
                $trimmed = ltrim($matches[1]);
            }

            $equals = strpos($trimmed, '=');
            if ($equals === false) {
                throw new ConfigParseException('Invalid .env entry: expected KEY=VALUE.', $lineNo, $line);
            }

            $key = trim(substr($trimmed, 0, $equals));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]*$/', $key) !== 1) {
                throw new ConfigParseException(sprintf('Invalid .env key "%s".', $key), $lineNo, $line);
            }

            $rawValue = ltrim(substr($trimmed, $equals + 1));
            $value    = $this->readValue($rawValue, $lines, $i, $lineNo);

            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * @param list<string> $lines
     */
    private function readValue(string $raw, array $lines, int &$index, int $lineNo): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];

        if ($quote !== '"' && $quote !== "'") {
            // Unquoted: strip an inline comment only when it follows whitespace.
            $value = preg_replace('/\s+#.*$/', '', $raw);

            return trim((string) $value);
        }

        $buffer    = $raw;
        $lineCount = count($lines);

        while (! $this->hasClosingQuote($buffer, $quote)) {
            $index++;
            if ($index >= $lineCount) {
                throw new ConfigParseException(
                    sprintf('Unterminated quoted value (started on line %d).', $lineNo),
                    $lineNo,
                    $raw,
                );
            }
            $buffer .= "\n" . $lines[$index];
        }

        $inner = $this->unwrapQuoted($buffer, $quote);

        return $quote === '"' ? $this->unescapeDouble($inner) : $inner;
    }

    private function hasClosingQuote(string $value, string $quote): bool
    {
        $length = strlen($value);

        for ($i = 1; $i < $length; $i++) {
            if ($quote === '"' && $value[$i] === '\\') {
                $i++;
                continue;
            }
            if ($value[$i] === $quote) {
                return true;
            }
        }

        return false;
    }

    private function unwrapQuoted(string $value, string $quote): string
    {
        $length  = strlen($value);
        $closing = $length - 1;

        for ($i = 1; $i < $length; $i++) {
            if ($quote === '"' && $value[$i] === '\\') {
                $i++;
                continue;
            }
            if ($value[$i] === $quote) {
                $closing = $i;
                break;
            }
        }

        $inner  = substr($value, 1, $closing - 1);
        $rest   = trim(substr($value, $closing + 1));

        if ($rest !== '' && ! str_starts_with($rest, '#')) {
            throw new ConfigParseException('Unexpected characters after a quoted value.');
        }

        return $inner;
    }

    private function unescapeDouble(string $value): string
    {
        return (string) preg_replace_callback(
            '/\\\\([nrt"\\\\])/',
            static fn (array $m): string => match ($m[1]) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                '"' => '"',
                '\\' => '\\',
                default => $m[0],
            },
            $value,
        );
    }
}

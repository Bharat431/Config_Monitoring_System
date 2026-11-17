<?php

namespace App\Domain\Parsing;

/**
 * Parser for Java-style .properties files.
 *
 * Supports `key=value`, `key: value`, whitespace separators, comments
 * (# and !), line continuations and the standard escape sequences.
 */
class PropertiesParser extends AbstractParser
{
    public function name(): string
    {
        return 'properties';
    }

    public function extensions(): array
    {
        return ['properties'];
    }

    public function supports(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'properties';
    }

    public function parse(string $contents, string $path = ''): array
    {
        $contents = $this->stripBom($contents);
        if (trim($contents) === '') {
            throw new ConfigParseException('Properties configuration is empty.');
        }

        $lines    = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $count    = count($lines);
        $data     = [];

        for ($i = 0; $i < $count; $i++) {
            $lineNo = $i + 1;
            $line   = ltrim($lines[$i]);

            if ($line === '' || $line[0] === '#' || $line[0] === '!') {
                continue;
            }

            // A trailing (unescaped) backslash continues on the next line.
            while ($this->endsWithContinuation($line) && $i + 1 < $count) {
                $line = substr($line, 0, -1) . ltrim($lines[++$i]);
            }

            if ($this->endsWithContinuation($line)) {
                $line = substr($line, 0, -1);
            }

            [$key, $value] = $this->splitKeyValue($line, $lineNo);
            $data[$this->unescape($key)] = $this->unescape($value);
        }

        return $data;
    }

    private function endsWithContinuation(string $line): bool
    {
        $backslashes = 0;
        $length      = strlen($line);

        for ($i = $length - 1; $i >= 0 && $line[$i] === '\\'; $i--) {
            $backslashes++;
        }

        return $backslashes % 2 === 1;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitKeyValue(string $line, int $lineNo): array
    {
        $length   = strlen($line);
        $separator = null;

        for ($i = 0; $i < $length; $i++) {
            if ($line[$i] === '\\') {
                $i++;
                continue;
            }

            if ($line[$i] === '=' || $line[$i] === ':' || $line[$i] === ' ' || $line[$i] === "\t") {
                $separator = $i;
                break;
            }
        }

        if ($separator === null) {
            return [$line, ''];
        }

        $key = rtrim(substr($line, 0, $separator));
        if ($key === '') {
            throw new ConfigParseException('Invalid .properties entry: empty key.', $lineNo, $line);
        }

        $rest = ltrim(substr($line, $separator));

        // Allow optional '=' or ':' after whitespace.
        if ($rest !== '' && ($rest[0] === '=' || $rest[0] === ':')) {
            $rest = ltrim(substr($rest, 1));
        }

        return [$key, $rest];
    }

    private function unescape(string $value): string
    {
        return (string) preg_replace_callback(
            '/\\\\(u[0-9a-fA-F]{4}|.)/s',
            static function (array $m): string {
                $token = $m[1];
                if (str_starts_with($token, 'u') && strlen($token) === 5) {
                    return mb_chr(hexdec(substr($token, 1)), 'UTF-8') ?: $token;
                }

                return match ($token) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'f' => "\f",
                    '\\' => '\\',
                    ':' => ':',
                    '=' => '=',
                    ' ' => ' ',
                    default => $token,
                };
            },
            $value,
        );
    }
}

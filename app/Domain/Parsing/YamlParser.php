<?php

namespace App\Domain\Parsing;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class YamlParser extends AbstractParser
{
    public function name(): string
    {
        return 'yaml';
    }

    public function extensions(): array
    {
        return ['yaml', 'yml'];
    }

    public function supports(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension === 'yaml' || $extension === 'yml';
    }

    public function parse(string $contents, string $path = ''): array
    {
        $contents = $this->stripBom($contents);

        if (trim($contents) === '') {
            throw new ConfigParseException('YAML configuration is empty.');
        }

        try {
            // PARSE_EXCEPTION_ON_INVALID_TYPE disables custom PHP object tags.
            $decoded = Yaml::parse($contents, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            throw new ConfigParseException(
                'Invalid YAML: ' . $e->getMessage(),
                $e->getParsedLine(),
                null,
                $e,
            );
        }

        return $this->assertMap($decoded, $path);
    }
}

<?php

namespace App\Domain\Parsing;

class ParserRegistry
{
    /**
     * @var list<ParserInterface>
     */
    private array $parsers;

    /**
     * @param list<ParserInterface>|null $parsers
     */
    public function __construct(?array $parsers = null)
    {
        $this->parsers = $parsers ?? [
            new EnvParser(),
            new JsonParser(),
            new YamlParser(),
            new PropertiesParser(),
        ];
    }

    public function forPath(string $path): ParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($path)) {
                return $parser;
            }
        }

        throw new ConfigParseException(sprintf(
            'No parser registered for "%s". Supported extensions: %s.',
            basename($path),
            implode(', ', $this->extensions()),
        ));
    }

    public function parse(string $path, string $contents): array
    {
        return $this->forPath($path)->parse($contents, $path);
    }

    public function supports(string $path): bool
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        $extensions = [];
        foreach ($this->parsers as $parser) {
            foreach ($parser->extensions() as $extension) {
                $extensions[] = strtolower(ltrim(trim($extension), '.'));
            }
        }

        return array_values(array_unique(array_filter($extensions)));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (ParserInterface $p): string => $p->name(), $this->parsers);
    }
}

<?php

namespace App\Domain\Parsing;

interface ParserInterface
{
    /** Human readable parser name. */
    public function name(): string;

    /**
     * File extensions (without leading dot) handled by this parser.
     *
     * @return list<string>
     */
    public function extensions(): array;

    /** Whether this parser can handle the given file path. */
    public function supports(string $path): bool;

    /**
     * Parse raw file contents into a nested array.
     *
     * @return array<string, mixed>
     *
     * @throws ConfigParseException when the input is invalid or incomplete
     */
    public function parse(string $contents, string $path = ''): array;
}

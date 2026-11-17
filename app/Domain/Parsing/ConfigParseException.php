<?php

namespace App\Domain\Parsing;

use RuntimeException;

class ConfigParseException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $sourceLine = null,
        private readonly ?string $snippet = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function sourceLine(): ?int
    {
        return $this->sourceLine;
    }

    public function snippet(): ?string
    {
        return $this->snippet;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return array_filter([
            'line'    => $this->sourceLine,
            'snippet' => $this->snippet,
        ], static fn (mixed $v): bool => $v !== null);
    }
}

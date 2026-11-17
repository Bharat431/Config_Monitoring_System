<?php

namespace App\Infrastructure\Http;

final class HttpResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly int $latencyMs = 0,
        public readonly ?string $error = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isRetryable(): bool
    {
        if ($this->statusCode === 0) {
            return true;
        }

        return $this->statusCode >= 500
            || in_array($this->statusCode, [408, 425, 429], true);
    }

    public function errorSummary(): string
    {
        if ($this->error !== null && $this->error !== '') {
            return $this->error;
        }

        $body = trim(preg_replace('/\s+/', ' ', $this->body) ?? '');

        return $snippet = $body === ''
            ? 'HTTP ' . $this->statusCode . ' with empty body'
            : 'HTTP ' . $this->statusCode . ': ' . mb_substr($body, 0, 300);
    }
}

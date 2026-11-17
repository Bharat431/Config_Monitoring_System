<?php

namespace App\Infrastructure\Http;

interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSec = 5,
    ): HttpResponse;
}

<?php

namespace Tests\Support\Fakes;

use App\Infrastructure\Http\HttpClientInterface;
use App\Infrastructure\Http\HttpResponse;

/**
 * Records outgoing HTTP calls and returns a canned response. Used by the
 * integration test to assert webhook contract details without a network.
 */
final class RecordingHttpClient implements HttpClientInterface
{
    /**
     * @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: int}>
     */
    public array $requests = [];

    /**
     * @param list<HttpResponse> $responses responses returned in order, last one repeats
     */
    public function __construct(private array $responses = [])
    {
        if ($this->responses === []) {
            $this->responses = [new HttpResponse(200, '{"ok":true}', [], 3)];
        }
    }

    public function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSec = 5,
    ): HttpResponse {
        $this->requests[] = [
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $body,
            'timeout' => $timeoutSec,
        ];

        if (count($this->responses) > 1) {
            return array_shift($this->responses);
        }

        return $this->responses[0];
    }

    public function lastRequest(): ?array
    {
        return $this->requests === [] ? null : $this->requests[count($this->requests) - 1];
    }
}

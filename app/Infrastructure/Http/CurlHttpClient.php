<?php

namespace App\Infrastructure\Http;

use App\Infrastructure\Logging\JsonLogger;
use Config\Monitor;
use Throwable;

class CurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly Monitor $config,
        private readonly UrlGuard $guard,
        private readonly JsonLogger $logger,
    ) {
    }

    public function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSec = 5,
    ): HttpResponse {
        $started = microtime(true);

        try {
            $this->guard->assertSafe($url);
        } catch (Throwable $e) {
            return new HttpResponse(0, '', [], $this->elapsed($started), 'Blocked: ' . $e->getMessage());
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return new HttpResponse(0, '', [], $this->elapsed($started), 'curl_init failed');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $timeout = max(1, $timeoutSec);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_USERAGENT      => $this->config->userAgent,
            CURLOPT_POSTFIELDS     => $body ?? '',
        ]);

        if (defined('CURLOPT_PROTOCOLS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        $responseBody = curl_exec($ch);
        $errorNo      = curl_errno($ch);
        $error        = curl_error($ch);
        $status       = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        curl_close($ch);

        $latency = $this->elapsed($started);

        if ($errorNo !== 0 || $responseBody === false) {
            $this->logger->warn('Webhook transport error', [
                'component' => 'http',
                'url_host'  => parse_url($url, PHP_URL_HOST),
                'curl_errno'=> $errorNo,
                'error'     => $error,
                'latency_ms'=> $latency,
            ]);

            return new HttpResponse(
                0,
                '',
                [],
                $latency,
                'Transport error (' . $errorNo . '): ' . $error,
            );
        }

        return new HttpResponse(
            $status,
            mb_substr((string) $responseBody, 0, 8192),
            $contentType === '' ? [] : ['content-type' => $contentType],
            $latency,
        );
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}

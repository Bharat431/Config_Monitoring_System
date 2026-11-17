<?php

namespace App\Infrastructure\Notifications;

use App\Infrastructure\Http\HttpClientInterface;
use App\Infrastructure\Http\HttpResponse;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\Metrics\Metrics;
use App\Support\Time;
use App\Support\Uuid;
use Config\Monitor;

class NotificationService
{
    public function __construct(
        private readonly Monitor $config,
        private readonly HttpClientInterface $http,
        private readonly PayloadFactory $payloads,
        private readonly WebhookSigner $signer,
        private readonly JsonLogger $logger,
        private readonly Metrics $metrics,
    ) {
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $event
     */
    public function send(array $target, array $event, ?int $deliveryId = null, int $attempt = 1): HttpResponse
    {
        $payload = $this->payloads->build((string) $target['type'], $event);

        try {
            $body = json_encode(
                $this->encodePayload($payload),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $e) {
            return new HttpResponse(0, '', [], 0, 'Could not encode webhook payload: ' . $e->getMessage());
        }

        $headers = $this->headers($target, $event, $body, $deliveryId, $attempt);

        $response = $this->http->send(
            'POST',
            (string) $target['url'],
            $headers,
            $body,
            max(1, $this->config->httpTimeoutSec),
        );

        $this->logger->debug('Webhook delivery attempt finished', [
            'component'    => 'notifier',
            'target_id'    => $target['id'] ?? null,
            'target_type'  => $target['type'] ?? null,
            'event_id'     => $event['event_id'] ?? null,
            'status_code'  => $response->statusCode,
            'latency_ms'   => $response->latencyMs,
            'success'      => $response->isSuccess(),
        ], $event['trace_id'] ?? null);

        return $response;
    }

    /**
     * Encode the canonical payload with stable JSON shapes.
     *
     * Change buckets are always objects even when their keys are numeric (a
     * config key "0" would otherwise make PHP treat the bucket as a list and
     * emit a JSON array). Nested lists still keep their array shape.
     *
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): object
    {
        $changes = $payload['changes'] ?? null;
        if (is_array($changes)) {
            $buckets = [];
            foreach ($changes as $bucket => $rows) {
                $buckets[(string) $bucket] = is_array($rows)
                    ? (object) $this->stringifyKeys($rows)
                    : $rows;
            }
            $payload['changes'] = (object) $buckets;
        }

        if (is_array($payload['summary'] ?? null)) {
            $payload['summary'] = (object) $payload['summary'];
        }
        if (is_array($payload['meta'] ?? null)) {
            $payload['meta'] = (object) $payload['meta'];
        }

        return (object) $payload;
    }

    /**
     * @param array<mixed, mixed> $rows
     *
     * @return array<string, mixed>
     */
    private function stringifyKeys(array $rows): array
    {
        $out = [];
        foreach ($rows as $key => $value) {
            $out[(string) $key] = $this->objectify($value);
        }

        return $out;
    }

    /** Recursively turn PHP hash maps into JSON objects, preserving lists. */
    private function objectify(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->objectify($item), $value);
        }

        return (object) $this->stringifyKeys($value);
    }

    /**
     * @param array<string, mixed> $target
     */
    public function test(array $target): HttpResponse
    {
        $event = [
            'event_id'  => Uuid::v4(),
            'source'    => 'config-monitor',
            'version'   => '1.0',
            'type'      => 'test',
            'file'      => 'demo-config.yaml',
            'path'      => '/demo/demo-config.yaml',
            'timestamp' => Time::isoNow(),
            'changes'   => ['added' => ['example.key' => 'value'], 'updated' => [], 'removed' => [], 'type_changes' => []],
            'summary'   => ['added' => 1, 'updated' => 0, 'removed' => 0, 'type_changes' => 0],
            'hash'      => null,
            'trace_id'  => Uuid::hex(16),
            'error'     => null,
            'meta'      => ['test' => true],
        ];

        return $this->send($target, $event, null, 0);
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $event
     *
     * @return array<string, string>
     */
    public function headers(array $target, array $event, string $body, ?int $deliveryId = null, int $attempt = 1): array
    {
        $headers = [];

        // Custom headers are applied first so the platform-owned delivery,
        // identity, tracing and signature headers below always win.
        $custom = json_decode((string) ($target['headers'] ?? ''), true);
        if (is_array($custom)) {
            foreach ($custom as $name => $value) {
                if (is_string($name) && preg_match('/^[A-Za-z0-9-]+$/', $name) === 1
                    && (is_scalar($value) || $value === null)) {
                    $headers[$name] = (string) $value;
                }
            }
        }

        $headers['Content-Type'] = 'application/json';
        $headers['Accept']       = 'application/json';
        $headers['User-Agent']   = $this->config->userAgent;
        $headers['X-Event-Id']   = (string) ($event['event_id'] ?? '');
        $headers['X-Event-Type'] = (string) ($event['type'] ?? 'change');
        $headers['X-Webhook-Timestamp'] = (string) time();

        if ($deliveryId !== null) {
            $headers['X-Delivery-Id'] = (string) $deliveryId;
            $headers['X-Delivery-Attempt'] = (string) $attempt;
        }

        $secret = (string) ($target['secret'] ?? '');
        if ($secret === '') {
            $secret = $this->config->webhookSecret;
        }

        $signature = $this->signer->sign($body, $secret);
        if ($signature !== null) {
            $headers['X-Signature'] = $signature;
        }

        $token = (string) ($target['token'] ?? '');
        if ($token === '') {
            $token = $this->config->webhookToken;
        }
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $traceId = (string) ($event['trace_id'] ?? '');
        if (preg_match('/^[0-9a-f]{32}$/', $traceId) === 1) {
            $headers['traceparent'] = sprintf('00-%s-%s-01', $traceId, Uuid::hex(8));
        }

        return $headers;
    }
}

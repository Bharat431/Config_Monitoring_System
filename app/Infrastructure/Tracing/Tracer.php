<?php

namespace App\Infrastructure\Tracing;

use App\Support\Uuid;
use Config\Monitor;

/**
 * Lightweight, dependency-free tracer.
 *
 * It emits W3C-ish trace ids (32 hex chars) and JSON span records, and can
 * propagate a `traceparent` header to webhooks. Swap the writer for an
 * OpenTelemetry exporter without touching the rest of the pipeline.
 */
class Tracer
{
    private string $traceId;

    public function __construct(private readonly Monitor $config)
    {
        $this->traceId = Uuid::hex(16);
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function adopt(?string $traceId): void
    {
        if (is_string($traceId) && preg_match('/^[0-9a-f]{32}$/i', $traceId) === 1) {
            $this->traceId = strtolower($traceId);
        }
    }

    public function newTraceId(): string
    {
        return $this->traceId = Uuid::hex(16);
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array{name: string, started_at: float, attributes: array<string, mixed>, span_id: string}
     */
    public function startSpan(string $name, array $attributes = []): array
    {
        return [
            'name'       => $name,
            'started_at' => microtime(true),
            'attributes' => $attributes,
            'span_id'    => Uuid::hex(8),
        ];
    }

    /**
     * @param array{name: string, started_at: float, attributes: array<string, mixed>, span_id: string} $span
     */
    public function endSpan(array $span, string $status = 'ok', ?string $error = null): void
    {
        if (! $this->config->tracingEnabled) {
            return;
        }

        $duration = (microtime(true) - $span['started_at']) * 1000;

        $record = [
            'ts'          => gmdate('Y-m-d\TH:i:s\Z'),
            'trace_id'    => $this->traceId,
            'span_id'     => $span['span_id'],
            'name'        => $span['name'],
            'duration_ms' => round($duration, 3),
            'status'      => $status,
            'attributes'  => $span['attributes'],
        ];

        if ($error !== null) {
            $record['error'] = $error;
        }

        $this->write($record);
    }

    public function traceparent(): string
    {
        return sprintf('00-%s-%s-01', $this->traceId, Uuid::hex(8));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function write(array $record): void
    {
        try {
            $dir = WRITEPATH . 'logs';
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $file = $dir . '/traces-' . gmdate('Y-m-d') . '.log';
            @file_put_contents(
                $file,
                json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
        } catch (\Throwable) {
            // Tracing must never break the pipeline.
        }
    }
}

<?php

namespace App\Infrastructure\Logging;

use Config\Monitor;
use Throwable;

/**
 * Structured JSON logger.
 *
 * Writes one JSON object per line to writable/logs/monitor-YYYY-MM-DD.log and
 * optionally mirrors to stderr (container friendly). Logging never throws.
 */
class JsonLogger
{
    private const LEVELS = [
        'debug' => 10,
        'info'  => 20,
        'warn'  => 30,
        'error' => 40,
    ];

    private int $threshold;

    public function __construct(private readonly Monitor $config)
    {
        $this->threshold = self::LEVELS[strtolower($config->logLevel)] ?? self::LEVELS['info'];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = [], ?string $traceId = null): void
    {
        $this->log('debug', $message, $context, $traceId);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = [], ?string $traceId = null): void
    {
        $this->log('info', $message, $context, $traceId);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warn(string $message, array $context = [], ?string $traceId = null): void
    {
        $this->log('warn', $message, $context, $traceId);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = [], ?string $traceId = null): void
    {
        $this->log('error', $message, $context, $traceId);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = [], ?string $traceId = null): void
    {
        $level = strtolower($level);
        if (! isset(self::LEVELS[$level]) || self::LEVELS[$level] < $this->threshold) {
            return;
        }

        try {
            $now  = microtime(true);
            $millis = (int) floor(($now - floor($now)) * 1000);

            $record = [
                'ts'       => gmdate('Y-m-d\TH:i:s', (int) $now) . '.' . sprintf('%03d', $millis) . 'Z',
                'level'    => $level,
                'message'  => $message,
                'pid'      => getmypid(),
                'host'     => gethostname() ?: 'unknown',
                'trace_id' => $traceId,
                'context'  => $this->normalize($context),
            ];

            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                $line = json_encode([
                    'ts'      => gmdate('c'),
                    'level'   => $level,
                    'message' => $message,
                    'context' => 'unserializable',
                ]);
            }

            $dir = WRITEPATH . 'logs';
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            @file_put_contents(
                $dir . '/monitor-' . gmdate('Y-m-d') . '.log',
                $line . PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );

            if ($this->config->logToStderr && PHP_SAPI === 'cli' && defined('STDERR')) {
                fwrite(STDERR, $line . PHP_EOL);
            }
        } catch (Throwable) {
            // Observability must never take down the caller.
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function normalize(array $context): array
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof Throwable) {
            return [
                'class'   => $value::class,
                'message' => $value->getMessage(),
                'file'    => $value->getFile(),
                'line'    => $value->getLine(),
            ];
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s\Z');
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeValue($item), $value);
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : $value::class;
        }

        return $value;
    }
}

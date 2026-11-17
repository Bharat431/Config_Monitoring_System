<?php

namespace App\Infrastructure\Metrics;

use App\Models\MetricCounterModel;
use Config\Monitor;
use Throwable;

/**
 * DB-backed metrics collector.
 *
 * Shared across web and worker processes so a Prometheus scrape from any
 * replica sees the full picture. Metric writes are best-effort: failures are
 * swallowed so the pipeline keeps moving.
 */
class Metrics
{
    public function __construct(
        private readonly Monitor $config,
        private readonly MetricCounterModel $model,
    ) {
    }

    /**
     * @param array<string, scalar> $labels
     */
    public function increment(string $name, array $labels = [], float $by = 1.0): void
    {
        $this->add($name, $labels, $by);
    }

    /**
     * Record a timing observation (exported as Prometheus summary).
     *
     * @param array<string, scalar> $labels
     */
    public function observe(string $name, float $value, array $labels = []): void
    {
        $this->add($name . '_sum', $labels, $value);
        $this->add($name . '_count', $labels, 1.0);
    }

    /**
     * @param array<string, scalar> $labels
     */
    public function gauge(string $name, float $value, array $labels = []): void
    {
        try {
            [$hash, $json] = $this->encodeLabels($labels);
            $this->model->setValue($this->sanitize($name), $hash, $json, $value);
        } catch (Throwable) {
            // Ignore metrics failures.
        }
    }

    public function renderPrometheus(): string
    {
        $namespace = $this->sanitize($this->config->metricsNamespace);
        $lines      = [];
        $rows       = [];

        try {
            $rows = $this->model->allRows();
        } catch (Throwable) {
            $rows = [];
        }

        foreach ($rows as $row) {
            $name   = $namespace . '_' . $this->sanitize((string) $row['name']);
            $labels = json_decode((string) ($row['labels'] ?? '{}'), true);
            $labels = is_array($labels) ? $labels : [];

            if (! isset($lines[$name])) {
                $type = str_ends_with($name, '_total') ? 'counter' : 'gauge';
                $lines[$name] = "# TYPE {$name} {$type}";
            }

            $renderedLabels = '';
            if ($labels !== []) {
                $pairs = [];
                foreach ($labels as $key => $value) {
                    $pairs[] = $this->sanitize((string) $key) . '="' . $this->escape((string) $value) . '"';
                }
                $renderedLabels = '{' . implode(',', $pairs) . '}';
            }

            $lines[$name . '|' . $renderedLabels] = $name . $renderedLabels . ' ' . $this->formatValue((float) $row['value']);
        }

        // Put the TYPE lines before samples by rebuilding in insertion order.
        $output = [];
        $seen   = [];
        foreach ($lines as $key => $line) {
            if (str_starts_with($key, '#') || ! str_contains($key, '|')) {
                $output[] = $line;
                continue;
            }

            [$metric] = explode('|', $key, 2);
            if (! isset($seen[$metric])) {
                $output[] = "# TYPE {$metric} " . (str_ends_with($metric, '_total') ? 'counter' : 'gauge');
                $seen[$metric] = true;
            }
            $output[] = $line;
        }

        return implode("\n", array_values(array_unique($output))) . "\n";
    }

    /**
     * @return array<string, float>
     */
    public function summary(): array
    {
        $out = [];

        try {
            foreach ($this->model->allRows() as $row) {
                $key = (string) $row['name'];
                $labels = json_decode((string) ($row['labels'] ?? '{}'), true);
                if (is_array($labels) && $labels !== []) {
                    $key .= '{' . http_build_query($labels, '', ',') . '}';
                }
                $out[$key] = (float) $row['value'];
            }
        } catch (Throwable) {
            return [];
        }

        return $out;
    }

    /**
     * @param array<string, scalar> $labels
     */
    private function add(string $name, array $labels, float $by): void
    {
        try {
            [$hash, $json] = $this->encodeLabels($labels);
            $this->model->add($this->sanitize($name), $hash, $json, $by);
        } catch (Throwable) {
            // Ignore metrics failures.
        }
    }

    /**
     * @param array<string, scalar> $labels
     *
     * @return array{0: string, 1: string}
     */
    private function encodeLabels(array $labels): array
    {
        ksort($labels);
        $json = json_encode($labels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        return [substr(hash('sha256', $json), 0, 32), $json];
    }

    private function sanitize(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_:]/', '_', $value) ?: 'metric';

        return ltrim($value, '0123456789');
    }

    private function escape(string $value): string
    {
        return str_replace(["\\", "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }

    private function formatValue(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0';
    }
}

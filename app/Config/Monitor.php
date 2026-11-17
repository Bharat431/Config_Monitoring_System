<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configuration for the Configuration Change Monitoring System.
 *
 * Every setting can be overridden with a CONFIGMON_* environment variable
 * (see `.env.example`). Explicitly reading the environment keeps the names
 * readable and avoids coupling them to the class name.
 */
class Monitor extends BaseConfig
{
    /** Optional API key. When empty the management API is open (dev only). */
    public string $apiKey = '';

    /** Comma separated list of directories that may be watched. */
    public string $watchRoots = '';

    /** File extensions that are allowed to be watched. */
    public array $allowedExtensions = ['env', 'json', 'yaml', 'yml', 'properties'];

    /** Poll interval for the watcher daemon, in milliseconds. */
    public int $pollIntervalMs = 2000;

    /** Default debounce window for rapid successive writes, in milliseconds. */
    public int $debounceMs = 750;

    /** Hard limit for a single config file (bytes). */
    public int $maxFileBytes = 2097152;

    /** Number of parsed snapshots kept per file. */
    public int $snapshotRetention = 20;

    /** Number of queue rows claimed per worker loop iteration. */
    public int $queueBatchSize = 25;

    /** Maximum delivery attempts before a job is moved to the DLQ. */
    public int $queueMaxAttempts = 6;

    /** Exponential backoff base and cap, in milliseconds. */
    public int $queueBaseDelayMs = 1000;
    public int $queueMaxDelayMs = 300000;

    /** How long a claimed job stays invisible before it is reaped, in seconds. */
    public int $queueVisibilityTimeoutSec = 120;

    /** HTTP timeout for webhook deliveries, in seconds. */
    public int $httpTimeoutSec = 5;

    /** Circuit breaker: failures before opening and cooldown, in seconds. */
    public int $circuitFailureThreshold = 5;
    public int $circuitCooldownSec = 60;

    /** Default webhook target, seeded by `php spark monitor:demo`. */
    public string $webhookUrl   = '';
    public string $webhookSecret = '';
    public string $webhookToken  = '';

    /** Allow private/loopback webhook destinations (needed for local sink). */
    public bool $allowPrivateWebhooks = false;

    /** Optional allow-list of webhook hosts, comma separated. */
    public string $webhookAllowedHosts = '';

    /** Enable demo-only endpoints that mutate sample config files. */
    public bool $demoMode = false;

    /** Structured log level: debug|info|warn|error. */
    public string $logLevel = 'info';

    /** Emit JSON logs to stderr (useful for containers). */
    public bool $logToStderr = false;

    /** Emit simple W3C-compatible trace ids and span records. */
    public bool $tracingEnabled = true;

    /** Prometheus namespace. */
    public string $metricsNamespace = 'configmon';

    /** User-Agent sent with webhook deliveries. */
    public string $userAgent = 'ConfigMonitor/1.0 (+https://example.invalid/config-monitor)';

    /** Retention for change events, delivery attempts and metrics, in days. */
    public int $retentionDays = 30;

    /** Watcher sharding for horizontal scale-out. */
    public int $watcherShard  = 1;
    public int $watcherShards = 1;

    public function __construct()
    {
        parent::__construct();

        $this->apiKey         = (string) env('CONFIGMON_API_KEY', $this->apiKey);
        $this->watchRoots     = (string) env('CONFIGMON_WATCH_ROOTS', $this->watchRoots);
        $this->webhookUrl     = (string) env('CONFIGMON_WEBHOOK_URL', $this->webhookUrl);
        $this->webhookSecret  = (string) env('CONFIGMON_WEBHOOK_SECRET', $this->webhookSecret);
        $this->webhookToken   = (string) env('CONFIGMON_WEBHOOK_TOKEN', $this->webhookToken);
        $this->userAgent      = (string) env('CONFIGMON_USER_AGENT', $this->userAgent);
        $this->logLevel       = strtolower((string) env('CONFIGMON_LOG_LEVEL', $this->logLevel));
        $this->metricsNamespace = (string) env('CONFIGMON_METRICS_NAMESPACE', $this->metricsNamespace);

        $this->pollIntervalMs         = (int) env('CONFIGMON_POLL_INTERVAL_MS', $this->pollIntervalMs);
        $this->debounceMs             = (int) env('CONFIGMON_DEBOUNCE_MS', $this->debounceMs);
        $this->maxFileBytes           = (int) env('CONFIGMON_MAX_FILE_BYTES', $this->maxFileBytes);
        $this->snapshotRetention      = (int) env('CONFIGMON_SNAPSHOT_RETENTION', $this->snapshotRetention);
        $this->queueBatchSize         = (int) env('CONFIGMON_QUEUE_BATCH_SIZE', $this->queueBatchSize);
        $this->queueMaxAttempts       = (int) env('CONFIGMON_QUEUE_MAX_ATTEMPTS', $this->queueMaxAttempts);
        $this->queueBaseDelayMs       = (int) env('CONFIGMON_QUEUE_BASE_DELAY_MS', $this->queueBaseDelayMs);
        $this->queueMaxDelayMs        = (int) env('CONFIGMON_QUEUE_MAX_DELAY_MS', $this->queueMaxDelayMs);
        $this->queueVisibilityTimeoutSec = (int) env('CONFIGMON_QUEUE_VISIBILITY_TIMEOUT_SEC', $this->queueVisibilityTimeoutSec);
        $this->httpTimeoutSec         = (int) env('CONFIGMON_HTTP_TIMEOUT_SEC', $this->httpTimeoutSec);
        $this->circuitFailureThreshold = (int) env('CONFIGMON_CIRCUIT_FAILURE_THRESHOLD', $this->circuitFailureThreshold);
        $this->circuitCooldownSec     = (int) env('CONFIGMON_CIRCUIT_COOLDOWN_SEC', $this->circuitCooldownSec);
        $this->retentionDays          = (int) env('CONFIGMON_RETENTION_DAYS', $this->retentionDays);
        $this->watcherShard           = (int) env('CONFIGMON_WATCHER_SHARD', $this->watcherShard);
        $this->watcherShards          = (int) env('CONFIGMON_WATCHER_SHARDS', $this->watcherShards);

        $this->allowPrivateWebhooks = $this->toBool(env('CONFIGMON_ALLOW_PRIVATE_WEBHOOKS', $this->allowPrivateWebhooks));
        $this->demoMode             = $this->toBool(env('CONFIGMON_DEMO_MODE', $this->demoMode));
        $this->logToStderr          = $this->toBool(env('CONFIGMON_LOG_TO_STDERR', $this->logToStderr));
        $this->tracingEnabled       = $this->toBool(env('CONFIGMON_TRACING_ENABLED', $this->tracingEnabled));

        $this->webhookAllowedHosts = (string) env('CONFIGMON_WEBHOOK_ALLOWED_HOSTS', $this->webhookAllowedHosts);

        $extensions = env('CONFIGMON_ALLOWED_EXTENSIONS');
        if (is_string($extensions) && $extensions !== '') {
            $this->allowedExtensions = array_values(array_filter(array_map(
                static fn (string $ext): string => strtolower(ltrim(trim($ext), '.')),
                explode(',', $extensions),
            )));
        }
    }

    /**
     * @return list<string>
     */
    public function watchRoots(): array
    {
        $roots = array_filter(array_map('trim', explode(',', $this->watchRoots)));

        if ($roots === []) {
            $roots = [WRITEPATH . 'sample-configs'];
        }

        $resolved = [];
        foreach ($roots as $root) {
            $real = realpath($root);
            $resolved[] = $real !== false ? $real : $root;
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @return list<string>
     */
    public function webhookAllowedHosts(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', $this->webhookAllowedHosts),
        )));
    }

    public function sampleDir(): string
    {
        return WRITEPATH . 'sample-configs';
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}

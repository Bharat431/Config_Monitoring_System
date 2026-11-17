<?php

namespace App\Services;

use App\Infrastructure\Logging\JsonLogger;
use App\Models\MonitoredFileModel;
use App\Models\NotificationTargetModel;
use App\Support\Time;
use Config\Monitor;
use Throwable;

/**
 * Creates an out-of-the-box demo environment.
 *
 * `seed()` writes a small set of representative configuration files into the
 * default watch root, registers them with the watcher and ensures a webhook
 * target exists. `simulateChange()` mutates one of those files and forces a
 * scan so the full pipeline (parse -> diff -> event bus -> queue) can be
 * exercised from the dashboard without touching a production file.
 */
class DemoService
{
    private const DEMO_TARGET_NAME = 'Local demo sink';
    private const DEMO_SINK_URL    = 'http://127.0.0.1:8090/hook';

    public function __construct(
        private readonly Monitor $config,
        private readonly WatcherService $watcher,
        private readonly MonitoredFileModel $files,
        private readonly NotificationTargetModel $targets,
        private readonly PathGuard $paths,
        private readonly JsonLogger $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function seed(bool $reset = false): array
    {
        $dir     = $this->ensureSampleDir();
        $written = [];
        $watched = [];

        foreach ($this->fixtures() as $name => $content) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;

            if ($reset || ! is_file($path)) {
                $this->write($path, $content);
                $written[] = $name;
            }
        }

        $target = $this->ensureDefaultTarget();

        foreach (array_keys($this->fixtures()) as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (! is_file($path)) {
                continue;
            }

            try {
                $result    = $this->watcher->addWatch($path, null, true, 'demo');
                $watched[] = [
                    'file'     => $result['file']['display_name'] ?? $name,
                    'path'     => $result['file']['path'] ?? $path,
                    'created'  => $result['created'],
                    'baseline' => $result['baseline']['outcome'] ?? 'unchanged',
                ];
            } catch (Throwable $e) {
                $this->logger->warn('Unable to register demo file', [
                    'component' => 'demo',
                    'file'      => $name,
                    'error'     => $e,
                ]);
            }
        }

        $this->logger->info('Demo environment seeded', [
            'component' => 'demo',
            'written'   => $written,
            'watched'   => count($watched),
            'target'    => $target['target']['id'] ?? null,
        ]);

        return [
            'directory' => $dir,
            'written'   => $written,
            'watched'   => $watched,
            'target'    => $target,
            'files'     => array_keys($this->fixtures()),
            'seeded_at' => Time::isoNow(),
        ];
    }

    /**
     * Apply a change of the given kind to one demo file and scan immediately.
     *
     * Kinds:
     *  - update      : overwrite the file with a fresh variant
     *  - type_change : switch a nested scalar between number and string
     *  - delete      : remove the file (emits a "deleted" event)
     *  - create      : delete + recreate the file (emits "deleted" then "created")
     *  - parse_error : write invalid syntax (emits a "parse_error" event)
     *
     * @return array<string, mixed>
     */
    public function simulateChange(?string $fileName = null, string $kind = 'update'): array
    {
        $kind    = strtolower(trim($kind));
        $allowed = ['update', 'type_change', 'delete', 'create', 'parse_error'];
        if (! in_array($kind, $allowed, true)) {
            throw new \InvalidArgumentException('Unknown demo change kind. Allowed: ' . implode(', ', $allowed) . '.');
        }

        $dir       = $this->ensureSampleDir();
        $available = array_keys($this->fixtures());

        if ($fileName === null || trim($fileName) === '') {
            $fileName = $available[random_int(0, count($available) - 1)];
        }

        $fileName = basename(str_replace('\\', '/', trim($fileName)));
        if (! in_array($fileName, $available, true)) {
            throw new \InvalidArgumentException('Demo can only mutate its own sample files.');
        }

        $path = $dir . DIRECTORY_SEPARATOR . $fileName;
        if (! is_file($path)) {
            $this->write($path, $this->fixtures()[$fileName]);
        }

        $results = [];

        if ($kind === 'delete' || $kind === 'create') {
            @unlink($path);
            $results[] = $this->scan($path);

            if ($kind === 'create') {
                $this->write($path, $this->variant($fileName, random_int(1000, 9999)));
                $results[] = $this->scan($path);
            }
        } elseif ($kind === 'parse_error') {
            $this->write($path, $this->invalidVariant($fileName));
            $results[] = $this->scan($path);
        } elseif ($kind === 'type_change') {
            $this->write($path, $this->typeChangeVariant($fileName, (string) @file_get_contents($path)));
            $results[] = $this->scan($path);
        } else {
            $this->write($path, $this->variant($fileName, random_int(1000, 9999)));
            $results[] = $this->scan($path);
        }

        $events = array_values(array_filter(array_map(
            static fn (array $result): ?array => $result['event'] !== null
                ? ['outcome' => $result['outcome'], 'event_id' => $result['event']->eventId, 'type' => $result['event']->changeType]
                : null,
            $results,
        )));

        $this->logger->info('Demo change simulated', [
            'component' => 'demo',
            'file'      => $fileName,
            'kind'      => $kind,
            'events'    => $events,
        ]);

        return [
            'file'     => $fileName,
            'path'     => $path,
            'kind'     => $kind,
            'results'  => array_map(
                static fn (array $result): array => ['outcome' => $result['outcome']],
                $results,
            ),
            'events'   => $events,
            'changed_at' => Time::isoNow(),
        ];
    }

    /**
     * Ensure the configured (or local) webhook target exists.
     *
     * @return array{created: bool, target: array<string, mixed>|null, reason?: string}
     */
    public function ensureDefaultTarget(): array
    {
        $url = trim($this->config->webhookUrl);

        if ($url === '') {
            if (! $this->config->allowPrivateWebhooks) {
                return [
                    'created' => false,
                    'target'  => null,
                    'reason'  => 'No CONFIGMON_WEBHOOK_URL configured and private webhooks are disabled.',
                ];
            }

            $url = self::DEMO_SINK_URL;
        }

        $existing = $this->targets->where('url', $url)->first();
        if ($existing !== null) {
            if (! (int) $existing['enabled']) {
                $this->targets->update((int) $existing['id'], ['enabled' => 1, 'updated_at' => Time::dbNow()]);
                $existing['enabled'] = 1;
            }

            return ['created' => false, 'target' => $existing];
        }

        $id = $this->targets->insert([
            'name'       => self::DEMO_TARGET_NAME,
            'type'       => 'webhook',
            'url'        => $url,
            'secret'     => $this->config->webhookSecret !== '' ? $this->config->webhookSecret : null,
            'token'      => $this->config->webhookToken !== '' ? $this->config->webhookToken : null,
            'headers'    => null,
            'enabled'    => 1,
            'created_at' => Time::dbNow(),
            'updated_at' => Time::dbNow(),
        ]);

        if ($id === false) {
            return ['created' => false, 'target' => null, 'reason' => 'Unable to persist the demo target.'];
        }

        return ['created' => true, 'target' => $this->targets->find((int) $id) ?? []];
    }

    /**
     * @return list<string>
     */
    public function sampleFiles(): array
    {
        return array_keys($this->fixtures());
    }

    public function sampleDir(): string
    {
        return $this->ensureSampleDir();
    }

    private function ensureSampleDir(): string
    {
        $dir = $this->config->sampleDir();
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * @return array{outcome: string, event: ?\App\Domain\Events\ChangeEvent}
     */
    private function scan(string $path): array
    {
        try {
            return $this->watcher->scanPath($path, false, null, true);
        } catch (Throwable $e) {
            $this->logger->error('Demo scan failed', [
                'component' => 'demo',
                'path'      => $path,
                'error'     => $e,
            ]);

            throw $e;
        }
    }

    private function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (@file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write demo file: ' . $path);
        }

        @chmod($path, 0664);
    }

    /**
     * @return array<string, string>
     */
    private function fixtures(): array
    {
        return [
            '.env' => <<<'ENV'
                # Demo application environment (safe to edit - watched by Config Monitor)
                APP_NAME="Config Monitor Demo"
                APP_ENV=production
                APP_DEBUG=false
                APP_VERSION=1.0.0

                LOG_LEVEL=info
                LOG_CHANNEL=stack

                DB_CONNECTION=mysql
                DB_HOST=127.0.0.1
                DB_PORT=3306
                DB_DATABASE=app
                DB_USERNAME=app
                DB_PASSWORD=secret

                CACHE_DRIVER=redis
                QUEUE_CONNECTION=database
                SESSION_DRIVER=redis
                ENV,

            'app.json' => json_encode([
                'app' => [
                    'name'    => 'Config Monitor Demo',
                    'version' => '1.0.0',
                    'debug'   => false,
                ],
                'server' => [
                    'host'    => '0.0.0.0',
                    'port'    => 8080,
                    'timeout' => 30,
                    'tls'     => ['enabled' => true, 'min_version' => '1.2'],
                ],
                'features' => [
                    'new_dashboard' => true,
                    'beta_api'      => false,
                ],
                'database' => [
                    'driver'   => 'mysql',
                    'host'     => '127.0.0.1',
                    'port'     => 3306,
                    'pool'     => ['min' => 2, 'max' => 20],
                ],
                'integrations' => ['slack', 'teams'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",

            'services.yaml' => <<<'YAML'
                # Demo service topology (watched by Config Monitor)
                services:
                  api:
                    image: registry.example.com/api:1.0.0
                    replicas: 2
                    resources:
                      cpu: 500m
                      memory: 512Mi
                    env:
                      LOG_LEVEL: info
                  worker:
                    image: registry.example.com/worker:1.0.0
                    replicas: 1
                    env:
                      LOG_LEVEL: info
                ingress:
                  enabled: true
                  host: api.example.com
                  tls: true
                feature_flags:
                  dark_mode: false
                  beta_search: false
                YAML,

            'application.properties' => <<<'PROP'
                # Demo Java-style configuration (watched by Config Monitor)
                server.port=8080
                server.servlet.context-path=/api
                spring.datasource.url=jdbc:mysql://127.0.0.1:3306/app
                spring.datasource.username=app
                spring.datasource.password=secret
                spring.jpa.hibernate.ddl-auto=validate
                logging.level.root=INFO
                feature.audit.enabled=true
                PROP,
        ];
    }

    private function variant(string $name, int $nonce): string
    {
        $versions = ['1.0.' . $nonce, '1.' . $nonce . '.0', '2.0.' . $nonce];
        $version  = $versions[array_rand($versions)];

        return match (true) {
            $name === '.env' => (string) preg_replace(
                '/^APP_VERSION=.*$/m',
                'APP_VERSION=' . $version,
                $this->fixtures()['.env'],
            ) . "\n# simulated change #" . $nonce . "\n",

            $name === 'app.json' => $this->jsonVariant($nonce),

            $name === 'services.yaml' => str_replace(
                ['image: registry.example.com/api:1.0.0', 'replicas: 2'],
                ['image: registry.example.com/api:' . $version, 'replicas: ' . (2 + ($nonce % 3))],
                $this->fixtures()['services.yaml'],
            ),

            default => str_replace(
                'logging.level.root=INFO',
                'logging.level.root=' . ($nonce % 2 === 0 ? 'DEBUG' : 'WARN'),
                $this->fixtures()['application.properties'],
            ) . "\n# simulated change #" . $nonce . "\n",
        };
    }

    private function jsonVariant(int $nonce): string
    {
        $data = json_decode($this->fixtures()['app.json'], true);
        if (! is_array($data)) {
            $data = [];
        }

        $data['app']['version']           = '1.0.' . $nonce;
        $data['server']['port']           = 8080 + ($nonce % 7);
        $data['server']['timeout']        = 25 + ($nonce % 20);
        $data['features']['new_dashboard'] = $nonce % 2 === 0;
        $data['database']['pool']['max']  = 10 + ($nonce % 20);
        $data['observability']            = [
            'metrics' => 'prometheus',
            'sample'  => $nonce / 100,
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function typeChangeVariant(string $name, string $current): string
    {
        if (str_ends_with($name, '.json')) {
            $data = json_decode($current, true);
            if (! is_array($data)) {
                $data = json_decode($this->fixtures()['app.json'], true) ?: [];
            }

            $currentPort = $data['server']['port'] ?? 8080;
            $data['server']['port'] = is_string($currentPort) ? 8080 : (string) $currentPort;

            $currentTls = $data['server']['tls']['enabled'] ?? true;
            $data['server']['tls']['enabled'] = is_bool($currentTls) ? (string) ($currentTls ? 'true' : 'false') : true;

            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }

        if (str_ends_with($name, '.yaml') || str_ends_with($name, '.yml')) {
            $content = $current !== '' ? $current : $this->fixtures()['services.yaml'];
            if (str_contains($content, 'replicas: "2"')) {
                return str_replace('replicas: "2"', 'replicas: 2', $content);
            }

            return (string) preg_replace('/^(  api:\R(?:.*\R)*?    replicas: )(\d+)$/m', '$1"$2"', $content, 1);
        }

        return $this->variant($name, random_int(1000, 9999));
    }

    private function invalidVariant(string $name): string
    {
        return match (true) {
            str_ends_with($name, '.json') => "{\n  \"server\": {\n    \"port\": 8080,\n    \"tls\": {\n",
            str_ends_with($name, '.yaml'), str_ends_with($name, '.yml') => "services:\n  api:\n    replicas: [2, 3\n",
            default => "THIS LINE IS NOT A VALID KEY VALUE PAIR\n",
        };
    }
}

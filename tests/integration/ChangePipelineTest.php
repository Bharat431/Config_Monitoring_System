<?php

use App\Domain\Diff\DiffEngine;
use App\Domain\Diff\Flattener;
use App\Domain\Parsing\ParserRegistry;
use App\Infrastructure\Http\HttpResponse;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\Metrics\Metrics;
use App\Infrastructure\Notifications\NotificationService;
use App\Infrastructure\Notifications\PayloadFactory;
use App\Infrastructure\Notifications\WebhookSigner;
use App\Infrastructure\Queue\EventBus;
use App\Infrastructure\Reliability\CircuitBreaker;
use App\Infrastructure\Tracing\Tracer;
use App\Models\ChangeEventModel;
use App\Models\CircuitBreakerModel;
use App\Models\DeadLetterModel;
use App\Models\DeliveryAttemptModel;
use App\Models\EventQueueModel;
use App\Models\FileSnapshotModel;
use App\Models\MetricCounterModel;
use App\Models\MonitoredFileModel;
use App\Models\NotificationTargetModel;
use App\Models\SystemStateModel;
use App\Services\PathGuard;
use App\Services\QueueWorkerService;
use App\Services\WatcherService;
use App\Support\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Monitor;
use Tests\Support\Fakes\RecordingHttpClient;

/**
 * Integration coverage for the complete pipeline:
 * file change -> parse -> diff -> event -> queue -> signed webhook -> delivery.
 *
 * @internal
 */
final class ChangePipelineTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';
    protected $DBGroup     = 'tests';

    private string $watchDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->watchDir = WRITEPATH . 'test-watch-' . bin2hex(random_bytes(6));
        mkdir($this->watchDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->watchDir);

        parent::tearDown();
    }

    public function testModificationProducesSignedWebhookDelivery(): void
    {
        $path = $this->writeJson('app.json', [
            'app'    => ['version' => '1.0.0', 'port' => 8080],
            'server' => ['timeout' => 30],
        ]);

        $this->createTarget();

        $http = new RecordingHttpClient();
        [$watcher, $worker] = $this->pipeline($http);

        $baseline = $watcher->addWatch($path, 0, true, 'test');
        $this->assertSame('unchanged', $baseline['baseline']['outcome'] ?? null);

        $this->writeJson('app.json', [
            'app'      => ['version' => '1.1.0', 'port' => '8080'],
            'server'   => ['timeout' => 30],
            'features' => ['metrics' => true],
        ]);

        $result = $watcher->scanPath($path, false, null, true);

        $this->assertSame('modified', $result['outcome']);
        $event = $result['event'];
        $this->assertNotNull($event);
        $this->assertSame('modified', $event->changeType);
        $this->assertSame(1, $event->summary['updated']);
        $this->assertSame(1, $event->summary['type_changes']);
        $this->assertSame(1, $event->summary['added']);

        $this->assertSame(1, $worker->processBatch(10));
        $this->assertCount(1, $http->requests);

        $request = (array) $http->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://hooks.example.test/config', $request['url']);

        $headers = $request['headers'];
        $this->assertSame($event->eventId, $headers['X-Event-Id']);
        $this->assertSame('modified', $headers['X-Event-Type']);
        $this->assertSame(
            'sha256=' . hash_hmac('sha256', (string) $request['body'], 'test-secret'),
            $headers['X-Signature'],
        );
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $headers['traceparent']);

        $payload = json_decode((string) $request['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('config-monitor', $payload['source']);
        $this->assertSame('1.0', $payload['version']);
        $this->assertSame($event->eventId, $payload['event_id']);
        $this->assertSame('app.json', $payload['file']);
        $this->assertSame('modified', $payload['type']);
        $this->assertSame('1.1.0', $payload['changes']['updated']['app.version']['new']);
        $this->assertSame('string', $payload['changes']['type_changes']['app.port']['newType']);
        $this->assertTrue($payload['changes']['added']['features.metrics']);
        $this->assertArrayHasKey('trace_id', $payload);
        $this->assertSame(['added' => 1, 'updated' => 1, 'removed' => 0, 'type_changes' => 1], $payload['summary']);

        $queueRow = model(EventQueueModel::class)->where('event_id', $event->eventId)->first();
        $this->assertNotNull($queueRow);
        $this->assertSame('delivered', $queueRow['status']);
        $this->assertSame(1, (int) $queueRow['attempts']);
        $this->assertSame(200, (int) $queueRow['last_status_code']);

        $this->assertSame(1, model(DeliveryAttemptModel::class)->where('event_id', $event->eventId)->where('outcome', 'success')->countAllResults());
    }

    public function testDeletionEmitsDeletedEventWithRemovedKeys(): void
    {
        $path = $this->writeJson('services.json', ['services' => ['api' => ['image' => 'api:1']]]);
        $this->createTarget();

        $http = new RecordingHttpClient();
        [$watcher, $worker] = $this->pipeline($http);

        $watcher->addWatch($path, 0, true, 'test');
        unlink($path);

        $result = $watcher->scanPath($path, false, null, true);

        $this->assertSame('deleted', $result['outcome']);
        $this->assertSame('deleted', $result['event']?->changeType);
        $this->assertArrayHasKey('services.api.image', $result['event']?->changes['removed'] ?? []);

        $this->assertSame(1, $worker->processBatch(5));

        $payload = json_decode((string) $http->lastRequest()['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('deleted', $payload['type']);
        $this->assertSame('api:1', $payload['changes']['removed']['services.api.image']);
    }

    public function testParseErrorKeepsLastGoodSnapshotForRecovery(): void
    {
        $path = $this->writeJson('config.json', ['enabled' => true, 'limit' => 10]);
        $this->createTarget();

        $http = new RecordingHttpClient();
        [$watcher, $worker] = $this->pipeline($http);

        $watcher->addWatch($path, 0, true, 'test');

        file_put_contents($path, '{"enabled": true, "limit":');
        $broken = $watcher->scanPath($path, false, null, true);

        $this->assertSame('parse_error', $broken['outcome']);
        $this->assertSame('parse_error', $broken['event']?->changeType);
        $this->assertNotNull($broken['event']?->error);

        // The file recovers; the diff must be relative to the last good snapshot.
        $this->writeJson('config.json', ['enabled' => true, 'limit' => 25]);
        $recovered = $watcher->scanPath($path, false, null, true);

        $this->assertSame('modified', $recovered['outcome']);
        $this->assertSame(['old' => 10, 'new' => 25], $recovered['event']?->changes['updated']['limit'] ?? null);

        $this->assertSame(2, $worker->processBatch(10));
        $this->assertCount(2, $http->requests);
    }

    public function testDebounceCollapsesRapidWrites(): void
    {
        $path = $this->writeJson('rapid.json', ['value' => 1]);
        $this->createTarget();

        $http = new RecordingHttpClient();
        [$watcher, $worker] = $this->pipeline($http, debounceMs: 5000);

        $watcher->addWatch($path, null, true, 'test');

        $this->writeJson('rapid.json', ['value' => 2]);
        $first = $watcher->scanPath($path);

        $this->writeJson('rapid.json', ['value' => 3]);
        $second = $watcher->scanPath($path);

        $this->assertSame('debounced', $first['outcome']);
        $this->assertSame('debounced', $second['outcome']);
        $this->assertSame(0, model(ChangeEventModel::class)->countAllResults());
        $this->assertSame(0, model(EventQueueModel::class)->countAllResults());

        // A forced scan (or the next poll after the window) emits a single event.
        $forced = $watcher->scanPath($path, false, null, true);
        $this->assertSame('modified', $forced['outcome']);
        $this->assertSame(1, model(ChangeEventModel::class)->countAllResults());

        $this->assertSame(1, $worker->processBatch(5));
    }

    public function testRetryableFailuresEventuallyMoveToDeadLetterQueue(): void
    {
        $path = $this->writeJson('flaky.json', ['retry' => false]);
        $this->createTarget();

        $http = new RecordingHttpClient([
            new HttpResponse(500, 'server exploded', [], 1),
            new HttpResponse(500, 'server exploded again', [], 1),
        ]);

        [$watcher, $worker] = $this->pipeline($http, maxAttempts: 2, baseDelayMs: 0, maxDelayMs: 0);

        $watcher->addWatch($path, 0, true, 'test');
        $this->writeJson('flaky.json', ['retry' => true]);
        $result = $watcher->scanPath($path, false, null, true);
        $eventId = $result['event']->eventId;

        $this->assertSame(2, $worker->processBatch(5));

        $queueRow = model(EventQueueModel::class)->where('event_id', $eventId)->first();
        $this->assertSame('dead', $queueRow['status']);
        $this->assertSame(2, (int) $queueRow['attempts']);

        $deadLetter = model(DeadLetterModel::class)->where('event_id', $eventId)->first();
        $this->assertNotNull($deadLetter);
        $this->assertSame(0, (int) $deadLetter['resolved']);

        $failures = model(DeliveryAttemptModel::class)
            ->where('event_id', $eventId)
            ->where('outcome', 'failure')
            ->countAllResults();
        $this->assertSame(2, $failures);
    }

    public function testDebounceUsesMillisecondPrecision(): void
    {
        $path = $this->writeJson('millis.json', ['value' => 1]);
        $this->createTarget();

        [$watcher] = $this->pipeline(new RecordingHttpClient(), debounceMs: 4000);
        $watcher->addWatch($path, null, true, 'test');
        $this->writeJson('millis.json', ['value' => 2]);

        $before = Time::nowMs();
        $first  = $watcher->scanPath($path);
        $after  = Time::nowMs();

        $this->assertSame('debounced', $first['outcome']);

        $files = model(MonitoredFileModel::class);
        $row   = $files->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row['pending_since_ms']);
        $this->assertGreaterThanOrEqual($before, (int) $row['pending_since_ms']);
        $this->assertLessThanOrEqual($after, (int) $row['pending_since_ms']);

        // Age the pending marker just past the window without sleeping.
        $files->update((int) $row['id'], ['pending_since_ms' => Time::nowMs() - 4001]);

        $second = $watcher->scanPath($path);
        $this->assertSame('modified', $second['outcome']);
        $this->assertSame(1, model(ChangeEventModel::class)->countAllResults());
    }

    public function testRetryBackoffIsExponentialWithinJitterBounds(): void
    {
        [, $worker] = $this->pipeline(
            new RecordingHttpClient(),
            baseDelayMs: 1000,
            maxDelayMs: 10000,
        );

        $method   = new \ReflectionMethod(QueueWorkerService::class, 'backoffMs');
        $averages = [];

        foreach ([1, 2, 3] as $attempt) {
            $samples = [];
            for ($i = 0; $i < 40; $i++) {
                $samples[] = $method->invoke($worker, $attempt);
            }

            $raw = 1000 * (2 ** ($attempt - 1));
            foreach ($samples as $ms) {
                $this->assertGreaterThanOrEqual((int) floor($raw * 0.8), $ms);
                $this->assertLessThanOrEqual((int) ceil($raw * 1.2), $ms);
            }

            $averages[$attempt] = array_sum($samples) / count($samples);
        }

        $this->assertGreaterThan($averages[1], $averages[2]);
        $this->assertGreaterThan($averages[2], $averages[3]);
    }

    public function testQueueOwnershipProtectsInFlightJobs(): void
    {
        $queue = model(EventQueueModel::class);
        $this->assertTrue($queue->enqueue('evt-ownership', 99));

        $job = $queue->claimNext('worker-a');
        $this->assertNotNull($job);
        $this->assertSame('processing', $job['status']);

        $id = (int) $job['id'];

        // A worker that does not hold the lease cannot mutate the job.
        $this->assertFalse($queue->updateOwned($id, 'worker-b', ['status' => 'delivered']));
        $this->assertSame('processing', $queue->find($id)['status']);

        $this->assertTrue($queue->updateOwned($id, 'worker-a', ['last_error' => 'still working']));
        $this->assertSame('still working', $queue->find($id)['last_error']);

        $this->assertSame(1, $queue->renewLease('worker-a', 30));
        $this->assertSame(0, $queue->renewLease('worker-b', 30));
    }

    public function testDeadLetterRetryResetsAttemptsAndRefailureReopensEntry(): void
    {
        $path = $this->writeJson('dlq-retry.json', ['retry' => false]);
        $this->createTarget();

        $http = new RecordingHttpClient([new HttpResponse(500, 'server exploded', [], 1)]);
        [$watcher, $worker] = $this->pipeline($http, maxAttempts: 1, baseDelayMs: 0, maxDelayMs: 0);

        $watcher->addWatch($path, 0, true, 'test');
        $this->writeJson('dlq-retry.json', ['retry' => true]);
        $result  = $watcher->scanPath($path, false, null, true);
        $eventId = $result['event']->eventId;

        $this->assertSame(1, $worker->processBatch(1));

        $deadLetters = model(DeadLetterModel::class);
        $first       = $deadLetters->where('event_id', $eventId)->first();
        $this->assertNotNull($first);

        $this->assertTrue($worker->retryDeadLetter((int) $first['id']));

        $queue = model(EventQueueModel::class)->where('event_id', $eventId)->first();
        $this->assertSame('pending', $queue['status']);
        $this->assertSame(0, (int) $queue['attempts']);
        $this->assertSame(1, (int) $deadLetters->find((int) $first['id'])['resolved']);

        $this->assertSame(1, $worker->processBatch(1));

        // The unique queue_id row is reopened instead of duplicated.
        $this->assertSame(1, $deadLetters->where('event_id', $eventId)->countAllResults());
        $reopened = $deadLetters->find((int) $first['id']);
        $this->assertSame(0, (int) $reopened['resolved']);
        $this->assertSame(1, (int) $reopened['attempts']);
    }

    /**
     * @return array{0: WatcherService, 1: QueueWorkerService}
     */
    private function pipeline(
        RecordingHttpClient $http,
        int $debounceMs = 0,
        int $maxAttempts = 6,
        int $baseDelayMs = 1000,
        int $maxDelayMs = 300000,
    ): array {
        $config = new Monitor();
        $config->watchRoots           = $this->watchDir;
        $config->debounceMs           = $debounceMs;
        $config->allowPrivateWebhooks = true;
        $config->webhookSecret        = 'test-secret';
        $config->httpTimeoutSec       = 1;
        $config->queueMaxAttempts     = $maxAttempts;
        $config->queueBaseDelayMs     = $baseDelayMs;
        $config->queueMaxDelayMs      = $maxDelayMs;
        $config->logLevel             = 'error';
        $config->tracingEnabled       = true;
        $config->metricsNamespace     = 'configmon_test';

        $logger  = new JsonLogger($config);
        $metrics = new Metrics($config, model(MetricCounterModel::class));
        $tracer  = new Tracer($config);

        $files     = model(MonitoredFileModel::class);
        $snapshots = model(FileSnapshotModel::class);
        $targets   = model(NotificationTargetModel::class);
        $queue     = model(EventQueueModel::class);
        $events    = model(ChangeEventModel::class);

        $flattener = new Flattener();
        $bus       = new EventBus($events, $targets, $queue, $metrics, $logger);

        $watcher = new WatcherService(
            $config,
            $files,
            $snapshots,
            new ParserRegistry(),
            new DiffEngine($flattener),
            $flattener,
            $bus,
            $metrics,
            $logger,
            $tracer,
            new PathGuard($config),
            model(SystemStateModel::class),
        );

        $notifications = new NotificationService(
            $config,
            $http,
            new PayloadFactory(),
            new WebhookSigner(),
            $logger,
            $metrics,
        );

        $worker = new QueueWorkerService(
            $config,
            $queue,
            $events,
            $targets,
            model(DeadLetterModel::class),
            model(DeliveryAttemptModel::class),
            new CircuitBreaker($config, model(CircuitBreakerModel::class), $logger),
            $notifications,
            $bus,
            $metrics,
            $logger,
            $tracer,
            model(SystemStateModel::class),
        );

        return [$watcher, $worker];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $name, array $data): string
    {
        $path = $this->watchDir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function createTarget(): void
    {
        model(NotificationTargetModel::class)->insert([
            'name'       => 'Integration sink',
            'type'       => 'webhook',
            'url'        => 'https://hooks.example.test/config',
            'secret'     => 'test-secret',
            'token'      => null,
            'headers'    => null,
            'enabled'    => 1,
            'created_at' => Time::dbNow(),
            'updated_at' => Time::dbNow(),
        ]);
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}

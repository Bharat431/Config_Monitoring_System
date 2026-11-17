<?php

use App\Domain\Events\ChangeEvent;
use App\Infrastructure\Notifications\NotificationService;
use App\Infrastructure\Notifications\PayloadFactory;
use App\Infrastructure\Notifications\WebhookSigner;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Monitor;
use Tests\Support\Fakes\RecordingHttpClient;
use Tests\Support\JsonSchemaValidator;

/**
 * Contract tests for webhook payloads and delivery headers.
 *
 * @internal
 */
final class WebhookPayloadContractTest extends CIUnitTestCase
{
    public function testCanonicalEventPayloadMeetsWebhookContract(): void
    {
        $event = $this->sampleEvent()->toArray();
        $payload = (new PayloadFactory())->build('webhook', $event);

        $this->assertSame('config-monitor', $payload['source']);
        $this->assertSame('1.0', $payload['version']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $payload['event_id'],
        );
        $this->assertSame('configs/app.yaml', $payload['file']);
        $this->assertSame('/srv/app/configs/app.yaml', $payload['path']);
        $this->assertSame('modified', $payload['type']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['timestamp']);
        $this->assertSame('sha256:abc123', $payload['hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $payload['trace_id']);
        $this->assertNull($payload['error']);
        $this->assertIsArray($payload['meta']);
        $this->assertSame(7, $payload['meta']['monitored_file_id']);

        $this->assertSame(['added' => 1, 'updated' => 1, 'removed' => 1, 'type_changes' => 1], $payload['summary']);
        $this->assertArrayHasKey('added', $payload['changes']);
        $this->assertArrayHasKey('updated', $payload['changes']);
        $this->assertArrayHasKey('removed', $payload['changes']);
        $this->assertArrayHasKey('type_changes', $payload['changes']);

        $this->assertSame('value', $payload['changes']['added']['features.new']);
        $this->assertSame(['old' => 1, 'new' => 2], $payload['changes']['updated']['app.replicas']);
        $this->assertSame('old', $payload['changes']['removed']['legacy.flag']);
        $this->assertSame(
            ['old' => '8080', 'new' => 8080, 'oldType' => 'string', 'newType' => 'integer'],
            $payload['changes']['type_changes']['app.port'],
        );

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertJson($encoded);
    }

    public function testCanonicalPayloadMatchesCommittedJsonSchema(): void
    {
        $schemaPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'docs'
            . DIRECTORY_SEPARATOR . 'change-event.schema.json';
        $this->assertFileExists($schemaPath);

        $schema = json_decode((string) file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);
        $event  = $this->sampleEvent()->toArray();

        $this->assertSame([], JsonSchemaValidator::validate($event, $schema));

        // The generic webhook provider emits the canonical payload unchanged.
        $payload = (new PayloadFactory())->build('webhook', $event);
        $this->assertSame([], JsonSchemaValidator::validate($payload, $schema));
    }

    public function testSchemaValidatorRejectsMalformedPayloads(): void
    {
        $schemaPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'docs'
            . DIRECTORY_SEPARATOR . 'change-event.schema.json';
        $schema = json_decode((string) file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);

        $invalid = $this->sampleEvent()->toArray();
        $invalid['event_id'] = 'not-a-uuid';
        $invalid['summary']['added'] = 'one';
        unset($invalid['trace_id']);
        $invalid['unexpected'] = true;

        $errors = JsonSchemaValidator::validate($invalid, $schema);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('event_id', implode("\n", $errors));
        $this->assertStringContainsString('trace_id', implode("\n", $errors));
        $this->assertStringContainsString('unexpected', implode("\n", $errors));
        $this->assertStringContainsString('summary.added', implode("\n", $errors));
    }

    public function testNumericPathKeysStayJsonObjects(): void
    {
        $config = new Monitor();
        $config->webhookSecret = 'secret';
        $config->httpTimeoutSec = 1;

        $http = new RecordingHttpClient();
        $service = new NotificationService(
            $config,
            $http,
            new PayloadFactory(),
            new WebhookSigner(),
            $this->createLogger($config),
            $this->createMetrics($config),
        );

        $original = $this->sampleEvent();
        $event = new ChangeEvent(
            eventId: $original->eventId,
            monitoredFileId: $original->monitoredFileId,
            fileName: $original->fileName,
            filePath: $original->filePath,
            changeType: $original->changeType,
            changes: [
                'added'        => ['0' => 'zero'],
                'updated'      => [],
                'removed'      => [],
                'type_changes' => [],
            ],
            summary: $original->summary,
            hash: $original->hash,
            timestamp: $original->timestamp,
            traceId: $original->traceId,
            error: $original->error,
        );

        $service->send(
            [
                'id' => 7,
                'type' => 'webhook',
                'url' => 'https://hooks.example.test/config',
                'secret' => 'secret',
                'token' => null,
                'headers' => null,
            ],
            $event->toArray(),
        );

        $request = $http->lastRequest();
        $this->assertNotNull($request);
        $body = (string) $request['body'];
        $this->assertStringContainsString('"changes":{"added":{"0":"zero"}', $body);
        $this->assertStringContainsString('"updated":{}', $body);
    }

    public function testNotificationHeadersMeetWebhookContract(): void
    {
        $config = new Monitor();
        $config->webhookSecret = 'global-secret';
        $config->userAgent     = 'ConfigMonitor/1.0-test';

        $service = new NotificationService(
            $config,
            new RecordingHttpClient(),
            new PayloadFactory(),
            new WebhookSigner(),
            $this->createLogger($config),
            $this->createMetrics($config),
        );

        $event = $this->sampleEvent()->toArray();
        $body  = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = $service->headers(
            [
                'id'      => 7,
                'type'    => 'webhook',
                'url'     => 'https://hooks.example.test/endpoint',
                'secret'  => 'target-secret',
                'token'   => 'api-token',
                'headers' => json_encode(['X-Custom' => 'yes']),
            ],
            $event,
            $body,
            42,
            3,
        );

        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame('application/json', $headers['Accept']);
        $this->assertSame('ConfigMonitor/1.0-test', $headers['User-Agent']);
        $this->assertSame($event['event_id'], $headers['X-Event-Id']);
        $this->assertSame('modified', $headers['X-Event-Type']);
        $this->assertMatchesRegularExpression('/^\d+$/', $headers['X-Webhook-Timestamp']);
        $this->assertSame('42', $headers['X-Delivery-Id']);
        $this->assertSame('3', $headers['X-Delivery-Attempt']);
        $this->assertSame('yes', $headers['X-Custom']);
        $this->assertSame('Bearer api-token', $headers['Authorization']);
        $this->assertSame(
            'sha256=' . hash_hmac('sha256', $body, 'target-secret'),
            $headers['X-Signature'],
        );
        $this->assertMatchesRegularExpression(
            '/^00-' . preg_quote($event['trace_id'], '/') . '-[0-9a-f]{16}-01$/',
            $headers['traceparent'],
        );
    }

    public function testWebhookSignerProducesVerifiableHmacContract(): void
    {
        $signer = new WebhookSigner();
        $body   = '{"event_id":"e1","type":"modified"}';

        $this->assertNull($signer->sign($body, null));
        $this->assertNull($signer->sign($body, ''));

        $signature = $signer->sign($body, 'secret');
        $this->assertSame('sha256=' . hash_hmac('sha256', $body, 'secret'), $signature);
        $this->assertTrue($signer->verify($body, 'secret', (string) $signature));
        $this->assertFalse($signer->verify($body, 'wrong', (string) $signature));
    }

    /**
     * @dataProvider providerPayloadContracts
     *
     * @param array<string, mixed> $expectedShape
     */
    public function testProviderPayloadContracts(string $type, array $expectedShape): void
    {
        $event   = $this->sampleEvent()->toArray();
        $payload = (new PayloadFactory())->build($type, $event);

        foreach ($expectedShape as $key => $expectedType) {
            $this->assertArrayHasKey($key, $payload, "Missing key '{$key}' for {$type} payload.");
            $this->assertSame(
                $expectedType,
                get_debug_type($payload[$key]),
                "Unexpected type for '{$key}' in {$type} payload.",
            );
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function providerPayloadContracts(): array
    {
        return [
            'generic webhook' => ['webhook', [
                'source' => 'string', 'version' => 'string', 'event_id' => 'string',
                'file' => 'string', 'path' => 'string', 'type' => 'string',
                'timestamp' => 'string', 'changes' => 'array', 'summary' => 'array',
                'trace_id' => 'string', 'meta' => 'array',
            ]],
            'slack' => ['slack', ['text' => 'string', 'blocks' => 'array']],
            'discord' => ['discord', ['content' => 'string', 'embeds' => 'array']],
            'teams' => ['teams', [
                '@type' => 'string', '@context' => 'string', 'summary' => 'string',
                'title' => 'string', 'sections' => 'array',
            ]],
        ];
    }

    private function sampleEvent(): ChangeEvent
    {
        return new ChangeEvent(
            eventId: '11111111-2222-4333-8444-555555555555',
            monitoredFileId: 7,
            fileName: 'configs/app.yaml',
            filePath: '/srv/app/configs/app.yaml',
            changeType: 'modified',
            changes: [
                'added'        => ['features.new' => 'value'],
                'updated'      => ['app.replicas' => ['old' => 1, 'new' => 2]],
                'removed'      => ['legacy.flag' => 'old'],
                'type_changes' => ['app.port' => ['old' => '8080', 'new' => 8080, 'oldType' => 'string', 'newType' => 'integer']],
            ],
            summary: ['added' => 1, 'updated' => 1, 'removed' => 1, 'type_changes' => 1],
            hash: 'sha256:abc123',
            timestamp: '2026-11-16T10:00:00Z',
            traceId: '00112233445566778899aabbccddeeff',
            error: null,
        );
    }

    private function createLogger(Monitor $config): \App\Infrastructure\Logging\JsonLogger
    {
        return new \App\Infrastructure\Logging\JsonLogger($config);
    }

    private function createMetrics(Monitor $config): \App\Infrastructure\Metrics\Metrics
    {
        return new \App\Infrastructure\Metrics\Metrics($config, model(\App\Models\MetricCounterModel::class));
    }
}
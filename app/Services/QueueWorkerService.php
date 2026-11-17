<?php

namespace App\Services;

use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\Metrics\Metrics;
use App\Infrastructure\Notifications\NotificationService;
use App\Infrastructure\Queue\EventBus;
use App\Infrastructure\Reliability\CircuitBreaker;
use App\Infrastructure\Tracing\Tracer;
use App\Models\ChangeEventModel;
use App\Models\DeadLetterModel;
use App\Models\DeliveryAttemptModel;
use App\Models\EventQueueModel;
use App\Models\NotificationTargetModel;
use App\Models\SystemStateModel;
use App\Support\Time;
use Config\Monitor;
use Throwable;

/**
 * Queue consumer.
 *
 * Delivery semantics are at-least-once: a job is only marked delivered after a
 * 2xx response, and consumers receive a stable X-Event-Id for de-duplication.
 * Failures use exponential backoff with jitter; non-retryable 4xx responses and
 * exhausted retries are moved to the dead-letter queue.
 */
class QueueWorkerService
{
    private readonly string $workerId;

    public function __construct(
        private readonly Monitor $config,
        private readonly EventQueueModel $queue,
        private readonly ChangeEventModel $events,
        private readonly NotificationTargetModel $targets,
        private readonly DeadLetterModel $deadLetters,
        private readonly DeliveryAttemptModel $attempts,
        private readonly CircuitBreaker $breaker,
        private readonly NotificationService $notifications,
        private readonly EventBus $bus,
        private readonly Metrics $metrics,
        private readonly JsonLogger $logger,
        private readonly Tracer $tracer,
        private readonly SystemStateModel $state,
    ) {
        $this->workerId = sprintf(
            '%s-%d-%s',
            gethostname() ?: 'worker',
            getmypid(),
            substr(bin2hex(random_bytes(4)), 0, 8),
        );
    }

    public function workerId(): string
    {
        return $this->workerId;
    }

    public function processBatch(int $limit): int
    {
        $processed = 0;

        for ($i = 0; $i < max(1, $limit); $i++) {
            $job = $this->queue->claimNext($this->workerId);
            if ($job === null) {
                break;
            }

            // Heartbeat before each delivery as well as after the batch. A slow
            // HTTP target must not make health checks report a false stale worker.
            $this->heartbeat($processed);

            $this->processJob($job);
            $processed++;
        }

        $this->heartbeat($processed);

        return $processed;
    }

    private function heartbeat(int $processed): void
    {
        // Extend the visibility lease on jobs still owned by this worker so a
        // slow webhook call cannot be reaped while it is in flight.
        $this->queue->renewLease($this->workerId, max(1, $this->config->queueVisibilityTimeoutSec));

        $this->state->put('worker_heartbeat', [
            'worker_id' => $this->workerId,
            'at'        => Time::isoNow(),
            'processed' => $processed,
            'queue'     => $this->queue->counts(),
        ]);
    }

    /**
     * @param array<string, mixed> $job
     */
    public function processJob(array $job): string
    {
        $span = $this->tracer->startSpan('queue.deliver', [
            'queue_id' => $job['id'] ?? null,
            'event_id' => $job['event_id'] ?? null,
            'target_id'=> $job['target_id'] ?? null,
        ]);

        try {
            $outcome = $this->doProcess($job);
            $this->tracer->endSpan($span, $outcome === 'failed' ? 'error' : 'ok');

            return $outcome;
        } catch (Throwable $e) {
            $this->logger->error('Queue job crashed', [
                'component' => 'worker',
                'queue_id'  => $job['id'] ?? null,
                'event_id'  => $job['event_id'] ?? null,
                'error'     => $e,
            ]);

            $attempt = ((int) ($job['attempts'] ?? 0)) + 1;
            if ($attempt >= max(1, $this->config->queueMaxAttempts)) {
                $this->markDead($job, null, 'processing_exception', $e->getMessage(), $attempt, null, null);
            } else {
                $this->reschedule($job, 'processing_exception: ' . $e->getMessage(), $attempt, null, 0);
            }
            $this->tracer->endSpan($span, 'error', $e->getMessage());

            return 'error';
        }
    }

    /**
     * @param array<string, mixed> $job
     */
    private function doProcess(array $job): string
    {
        $queueId  = (int) $job['id'];
        $targetId = (int) $job['target_id'];
        $eventId  = (string) $job['event_id'];

        $eventRow = $this->events->findByEventId($eventId);
        $target   = $this->targets->find($targetId);

        if ($eventRow === null || $target === null) {
            $reason = $eventRow === null ? 'missing_event' : 'missing_target';
            $this->markDead($job, $eventRow, $reason, 'Event or notification target no longer exists.', (int) ($job['attempts'] ?? 0), null, null);

            return 'dead';
        }

        $event = json_decode((string) $eventRow['payload'], true);
        if (! is_array($event)) {
            $this->markDead($job, $eventRow, 'invalid_payload', 'Stored event payload is not valid JSON.', (int) ($job['attempts'] ?? 0), null, null);

            return 'dead';
        }

        if (empty($target['enabled'])) {
            $this->markDead($job, $eventRow, 'target_disabled', 'Notification target is disabled.', (int) ($job['attempts'] ?? 0), null, null);

            return 'dead';
        }

        $breakerState = $this->breaker->check($targetId);
        if (! $breakerState['allowed']) {
            $this->defer($job, (string) ($breakerState['retry_at'] ?? ''), 'circuit_open');

            return 'deferred';
        }

        $attempt  = ((int) $job['attempts']) + 1;
        $response = $this->notifications->send($target, $event, $queueId, $attempt);

        if ($response->isSuccess()) {
            $now = Time::dbNow();
            $this->queue->updateOwned($queueId, $this->workerId, [
                'status'           => 'delivered',
                'attempts'         => $attempt,
                'delivered_at'     => $now,
                'locked_at'        => null,
                'locked_by'        => null,
                'last_error'       => null,
                'last_status_code' => $response->statusCode,
                'latency_ms'       => $response->latencyMs,
                'updated_at'       => $now,
            ]);

            $this->attempts->record(
                $queueId,
                $eventId,
                $targetId,
                $attempt,
                'success',
                $response->statusCode,
                $response->latencyMs,
            );

            $this->breaker->recordSuccess($targetId);
            $this->metrics->increment('notifications_total', ['status' => 'success', 'target_type' => (string) $target['type']]);
            $this->metrics->observe('delivery_latency_seconds', $response->latencyMs / 1000, ['target_type' => (string) $target['type']]);

            $this->logger->info('Notification delivered', [
                'component'   => 'worker',
                'queue_id'    => $queueId,
                'event_id'    => $eventId,
                'target_id'   => $targetId,
                'attempt'     => $attempt,
                'status_code' => $response->statusCode,
                'latency_ms'  => $response->latencyMs,
            ], (string) ($event['trace_id'] ?? ''));

            return 'delivered';
        }

        $error = $response->errorSummary();

        $this->attempts->record(
            $queueId,
            $eventId,
            $targetId,
            $attempt,
            'failure',
            $response->statusCode,
            $response->latencyMs,
            $error,
        );

        $this->breaker->recordFailure($targetId, $error);
        $this->metrics->increment('notifications_total', ['status' => 'failure', 'target_type' => (string) $target['type']]);

        if (! $response->isRetryable()) {
            $this->markDead(
                $job,
                $eventRow,
                'non_retryable_http_' . $response->statusCode,
                $error,
                $attempt,
                $response->statusCode,
                $response->latencyMs,
            );

            return 'dead';
        }

        if ($attempt >= max(1, $this->config->queueMaxAttempts)) {
            $this->markDead(
                $job,
                $eventRow,
                'max_attempts_exceeded',
                $error,
                $attempt,
                $response->statusCode,
                $response->latencyMs,
            );

            return 'dead';
        }

        $this->reschedule($job, $error, $attempt, $response->statusCode, $response->latencyMs);

        return 'retry';
    }

    /**
     * @param array<string, mixed> $job
     */
    private function reschedule(array $job, string $error, int $attempt, ?int $statusCode, int $latencyMs): void
    {
        $backoffMs = $this->backoffMs(max(1, $attempt));
        $now       = Time::dbNow();

        $this->queue->updateOwned((int) $job['id'], $this->workerId, [
            'status'           => 'pending',
            'attempts'         => $attempt,
            'available_at'     => Time::dbAfterMs($backoffMs),
            'available_at_ms'  => Time::afterMs($backoffMs),
            'locked_at'        => null,
            'locked_by'        => null,
            'last_error'       => mb_substr($error, 0, 2000),
            'last_status_code' => $statusCode,
            'latency_ms'       => $latencyMs,
            'updated_at'       => $now,
        ]);

        $this->metrics->increment('notifications_retried_total');
        $this->metrics->increment('retry_scheduled_total');

        $this->logger->warn('Notification failed; retry scheduled', [
            'component'   => 'worker',
            'queue_id'    => $job['id'],
            'attempt'     => $attempt,
            'backoff_ms'  => $backoffMs,
            'status_code' => $statusCode,
            'error'       => $error,
        ]);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function defer(array $job, string $retryAt, string $reason): void
    {
        $deferrals = ((int) $job['deferrals']) + 1;
        $now       = Time::dbNow();

        if ($deferrals > max(10, $this->config->queueMaxAttempts * 3)) {
            $this->markDead($job, null, $reason, 'Circuit stayed open; giving up.', (int) $job['attempts'], null, null);

            return;
        }

        $retryMs = max(1000, $this->config->circuitCooldownSec * 1000);
        $availableAt = $retryAt !== '' ? $retryAt : Time::dbAfterMs($retryMs);

        $this->queue->updateOwned((int) $job['id'], $this->workerId, [
            'status'          => 'pending',
            'deferrals'       => $deferrals,
            'available_at'    => $availableAt,
            'available_at_ms' => $retryAt !== '' ? null : Time::afterMs($retryMs),
            'locked_at'       => null,
            'locked_by'       => null,
            'last_error'      => $reason,
            'updated_at'      => $now,
        ]);

        $this->metrics->increment('notifications_deferred_total');

        $this->logger->warn('Delivery deferred by circuit breaker', [
            'component'    => 'worker',
            'queue_id'     => $job['id'],
            'available_at' => $availableAt,
            'deferrals'    => $deferrals,
        ]);
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed>|null $eventRow
     */
    private function markDead(
        array $job,
        ?array $eventRow,
        string $reason,
        string $error,
        int $attempts,
        ?int $statusCode,
        ?int $latencyMs,
    ): void {
        $now = Time::dbNow();

        $this->queue->updateOwned((int) $job['id'], $this->workerId, [
            'status'           => 'dead',
            'attempts'         => $attempts,
            'locked_at'        => null,
            'locked_by'        => null,
            'last_error'       => mb_substr($error, 0, 2000),
            'last_status_code' => $statusCode,
            'latency_ms'       => $latencyMs,
            'updated_at'       => $now,
        ]);

        // dead_letters.queue_id is unique. Reopen the historical row on a
        // re-failure so a retried DLQ entry becomes outstanding again without
        // violating that constraint or losing the operator's audit trail.
        $entry = [
            'queue_id'    => (int) $job['id'],
            'event_id'    => (string) $job['event_id'],
            'target_id'   => (int) $job['target_id'],
            'attempts'    => $attempts,
            'reason'      => $reason,
            'error'       => mb_substr($error, 0, 4000),
            'payload'     => (string) ($eventRow['payload'] ?? '{}'),
            'resolved'    => 0,
            'resolved_at' => null,
            'created_at'  => $now,
        ];

        $existing = $this->deadLetters->findAnyByQueueId((int) $job['id']);
        if ($existing === null) {
            $this->deadLetters->insert($entry);
        } else {
            $this->deadLetters->update((int) $existing['id'], $entry);
        }

        $this->metrics->increment('dlq_total');
        $this->metrics->increment('notifications_total', ['status' => 'dead', 'target_type' => 'unknown']);

        $this->logger->error('Notification moved to dead-letter queue', [
            'component'   => 'worker',
            'queue_id'    => $job['id'],
            'event_id'    => $job['event_id'],
            'reason'      => $reason,
            'attempts'    => $attempts,
            'status_code' => $statusCode,
        ]);
    }

    private function backoffMs(int $attempt): int
    {
        $base = max(0, $this->config->queueBaseDelayMs);
        if ($base === 0) {
            return 0;
        }

        $cap  = max($base, $this->config->queueMaxDelayMs);
        $raw  = (int) min($cap, $base * (2 ** max(0, $attempt - 1)));

        // ±20% jitter avoids thundering herds when a downstream service recovers.
        $jitter = (int) floor($raw * (random_int(-20, 20) / 100));

        return max(1, min($cap, $raw + $jitter));
    }

    public function reapStale(): int
    {
        $reset = $this->queue->resetStale(max(1, $this->config->queueVisibilityTimeoutSec));

        if ($reset > 0) {
            $this->logger->warn('Requeued stale in-flight jobs', [
                'component' => 'worker',
                'count'     => $reset,
            ]);
            $this->metrics->increment('queue_reaped_total', [], (float) $reset);
        }

        return $reset;
    }

    /**
     * Replay an event to all currently enabled targets.
     *
     * @return array{event_id: string, requeued: int}
     */
    public function replayEvent(string $eventId): array
    {
        $event = $this->events->findByEventId($eventId);
        if ($event === null) {
            throw new \InvalidArgumentException('Event not found.');
        }

        $requeued = $this->queue->resetForReplay(
            $eventId,
            array_map(static fn (array $t): int => (int) $t['id'], $this->targets->enabled()),
        );
        $missing  = $this->bus->enqueueMissingTargets($eventId);
        $this->events->incrementReplay((int) $event['id']);

        $this->metrics->increment('events_replayed_total');
        $this->logger->info('Event replayed', [
            'component' => 'worker',
            'event_id'  => $eventId,
            'requeued'  => $requeued + $missing,
        ], (string) ($event['trace_id'] ?? ''));

        return ['event_id' => $eventId, 'requeued' => $requeued + $missing];
    }

    public function retryDeadLetter(int $deadLetterId): bool
    {
        $dlq = $this->deadLetters->find($deadLetterId);
        if ($dlq === null || (int) $dlq['resolved'] === 1) {
            return false;
        }

        $queueRow = $this->queue->find((int) $dlq['queue_id']);
        if ($queueRow === null) {
            $this->deadLetters->resolve($deadLetterId);

            return false;
        }

        // Only requeue a job that is truly dead. If a worker is still handling
        // it, leave the dead letter unresolved so the operator can retry later.
        if (! $this->queue->resetDeadForRetry((int) $queueRow['id'])) {
            return false;
        }

        $this->deadLetters->resolve($deadLetterId);
        $this->metrics->increment('dlq_retried_total');

        $this->logger->info('Dead letter requeued', [
            'component'     => 'worker',
            'dead_letter_id'=> $deadLetterId,
            'queue_id'      => $queueRow['id'],
        ]);

        return true;
    }
}

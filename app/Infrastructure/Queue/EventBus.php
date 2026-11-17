<?php

namespace App\Infrastructure\Queue;

use App\Domain\Events\ChangeEvent;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\Metrics\Metrics;
use App\Models\ChangeEventModel;
use App\Models\EventQueueModel;
use App\Models\NotificationTargetModel;
use Throwable;

/**
 * Durable event bus backed by the database.
 *
 * `publish()` persists the event and fans it out into one queue row per enabled
 * target inside a single transaction. `stage()` is the transaction-aware
 * primitive used by the watcher so the snapshot update, the change event and
 * the queue fan-out commit atomically.
 */
class EventBus
{
    public function __construct(
        private readonly ChangeEventModel $events,
        private readonly NotificationTargetModel $targets,
        private readonly EventQueueModel $queue,
        private readonly Metrics $metrics,
        private readonly JsonLogger $logger,
    ) {
    }

    public function publish(ChangeEvent $event): bool
    {
        $db = db_connect();
        $db->transBegin();

        try {
            $queued = $this->stage($event);
            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();

            $this->logger->error('Failed to publish event', [
                'component' => 'event_bus',
                'event_id'  => $event->eventId,
                'error'     => $e,
            ], $event->traceId);

            throw $e;
        }

        if ($queued >= 0) {
            $this->recordPublished($event, $queued);
        }

        return $queued >= 0;
    }

    /**
     * Persist the event and enqueue it for every enabled target.
     *
     * MUST be called inside an open transaction owned by the caller.
     *
     * @return int number of queue rows created, or -1 when the event is a duplicate
     */
    public function stage(ChangeEvent $event): int
    {
        if ($this->events->findByEventId($event->eventId) !== null) {
            return -1;
        }

        if ($this->events->insert($event->toDatabaseRow()) === false) {
            throw new \RuntimeException('Failed to persist change event.');
        }

        $queued = 0;
        foreach ($this->targets->enabled() as $target) {
            if ($this->queue->enqueue($event->eventId, (int) $target['id'])) {
                $queued++;
            }
        }

        return $queued;
    }

    public function recordPublished(ChangeEvent $event, int $queued): void
    {
        $this->metrics->increment('changes_detected_total', ['type' => $event->changeType]);
        $this->metrics->increment('queue_enqueued_total', [], (float) $queued);

        $this->logger->info('Change event published', [
            'component'   => 'event_bus',
            'event_id'    => $event->eventId,
            'file'        => $event->fileName,
            'change_type' => $event->changeType,
            'summary'     => $event->summary,
            'targets'     => $queued,
        ], $event->traceId);

        if ($queued === 0) {
            $this->logger->warn('Event stored but no enabled notification targets', [
                'component' => 'event_bus',
                'event_id'  => $event->eventId,
            ], $event->traceId);
        }
    }

    public function enqueueMissingTargets(string $eventId): int
    {
        $enqueued = 0;
        foreach ($this->targets->enabled() as $target) {
            if ($this->queue->enqueue($eventId, (int) $target['id'])) {
                $enqueued++;
            }
        }

        return $enqueued;
    }
}

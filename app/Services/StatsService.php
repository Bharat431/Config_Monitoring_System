<?php

namespace App\Services;

use App\Models\ChangeEventModel;
use App\Models\CircuitBreakerModel;
use App\Models\DeadLetterModel;
use App\Models\DeliveryAttemptModel;
use App\Models\EventQueueModel;
use App\Models\MonitoredFileModel;
use App\Models\NotificationTargetModel;
use App\Models\SystemStateModel;
use App\Support\Time;
use Config\Monitor;
use Throwable;

class StatsService
{
    public function __construct(
        private readonly Monitor $config,
        private readonly MonitoredFileModel $files,
        private readonly ChangeEventModel $events,
        private readonly EventQueueModel $queue,
        private readonly DeliveryAttemptModel $attempts,
        private readonly DeadLetterModel $deadLetters,
        private readonly CircuitBreakerModel $breakers,
        private readonly NotificationTargetModel $targets,
        private readonly SystemStateModel $state,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $since24h = gmdate('Y-m-d H:i:s', time() - 86400);

        $statusCounts    = $this->groupCount($this->files, 'status');
        $extensionCounts = $this->groupCount($this->files, 'extension');
        $typeCounts      = $this->groupCount($this->events, 'change_type');

        $queueCounts  = $this->queue->counts();
        $delivered    = (int) $queueCounts['delivered'];
        $dead         = (int) $queueCounts['dead'];
        $denominator  = $delivered + $dead;
        $successRate  = $denominator > 0 ? round(($delivered / $denominator) * 100, 2) : null;

        $avgLatency = null;
        try {
            $row = $this->queue->selectAvg('latency_ms', 'avg_latency')
                ->where('status', 'delivered')
                ->first();
            $avgLatency = $row !== null && $row['avg_latency'] !== null
                ? round((float) $row['avg_latency'], 1)
                : null;
        } catch (Throwable) {
            // ignore
        }

        $recent = $this->events->select('id, event_id, file_name, change_type, payload, summary_added, summary_updated, summary_removed, summary_type_changes, trace_id, replay_count, created_at')
            ->orderBy('id', 'DESC')
            ->findAll(8);

        $series = $this->hourlySeries(24);

        return [
            'generated_at' => Time::isoNow(),
            'files'        => [
                'total'       => array_sum($statusCounts),
                'active'      => $statusCounts['active'] ?? 0,
                'error'       => $statusCounts['error'] ?? 0,
                'paused'      => $statusCounts['paused'] ?? 0,
                'deleted'     => $statusCounts['deleted'] ?? 0,
                'missing'     => $statusCounts['missing'] ?? 0,
                'pending'     => $statusCounts['pending'] ?? 0,
                'by_status'   => $statusCounts,
                'by_extension'=> $extensionCounts,
            ],
            'events' => [
                'total'      => array_sum($typeCounts),
                'last_24h'   => $this->events->where('created_at >=', $since24h)->countAllResults(),
                'by_type'    => $typeCounts,
                'series'     => $series,
                'recent'     => array_map([$this, 'presentEventRow'], $recent),
            ],
            'notifications' => [
                'delivered'    => $delivered,
                'dead'         => $dead,
                'pending'      => (int) $queueCounts['pending'],
                'processing'   => (int) $queueCounts['processing'],
                'success_rate' => $successRate,
                'avg_latency_ms' => $avgLatency,
                'attempts_total' => (int) $this->attempts->countAllResults(),
                'attempts_failed'=> (int) $this->attempts->where('outcome', 'failure')->countAllResults(),
                'dlq_open'     => (int) $this->deadLetters->unresolvedCount(),
            ],
            'targets' => [
                'total'   => (int) $this->targets->countAllResults(),
                'enabled' => count($this->targets->enabled()),
            ],
            'health' => [
                'circuit_open' => (int) $this->breakers->openCount(),
                'watcher'      => $this->state->get('watcher_heartbeat'),
                'worker'       => $this->state->get('worker_heartbeat'),
                'last_scan'    => $this->state->get('last_scan'),
            ],
            'queue' => $queueCounts,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function hourlySeries(int $hours = 24): array
    {
        $hours = max(1, min(168, $hours));
        $since = gmdate('Y-m-d H:i:s', time() - ($hours - 1) * 3600);

        $rows = $this->events->select('created_at, change_type')
            ->where('created_at >=', $since)
            ->findAll();

        $buckets = [];
        for ($i = $hours - 1; $i >= 0; $i--) {
            $ts  = time() - ($i * 3600);
            $key = gmdate('Y-m-d H:00', $ts);
            $buckets[$key] = [
                'bucket'      => $key,
                'label'       => gmdate('H:00', $ts),
                'total'       => 0,
                'created'     => 0,
                'modified'    => 0,
                'deleted'     => 0,
                'parse_error' => 0,
            ];
        }

        foreach ($rows as $row) {
            $ts  = strtotime((string) $row['created_at'] . ' UTC');
            if ($ts === false) {
                continue;
            }
            $key = gmdate('Y-m-d H:00', $ts);
            if (! isset($buckets[$key])) {
                continue;
            }

            $buckets[$key]['total']++;
            $type = (string) $row['change_type'];
            if (isset($buckets[$key][$type])) {
                $buckets[$key][$type]++;
            }
        }

        return array_values($buckets);
    }

    /**
     * @return array<string, mixed>
     */
    public function eventDetail(string $eventId): ?array
    {
        $event = $this->events->findByEventId($eventId);
        if ($event === null) {
            return null;
        }

        $payload = json_decode((string) $event['payload'], true);

        return [
            'event'    => [
                'id'          => (int) $event['id'],
                'event_id'    => $event['event_id'],
                'file_name'   => $event['file_name'],
                'file_path'   => $event['file_path'],
                'change_type' => $event['change_type'],
                'trace_id'    => $event['trace_id'],
                'replay_count'=> (int) $event['replay_count'],
                'error'       => $event['error'],
                'created_at'  => Time::isoFromDb((string) $event['created_at']),
                'payload'     => is_array($payload) ? $payload : null,
            ],
            'deliveries' => array_map(static fn (array $row): array => [
                'id'              => (int) $row['id'],
                'target_id'       => (int) $row['target_id'],
                'status'          => $row['status'],
                'attempts'        => (int) $row['attempts'],
                'last_error'      => $row['last_error'],
                'last_status_code'=> $row['last_status_code'] === null ? null : (int) $row['last_status_code'],
                'latency_ms'      => $row['latency_ms'] === null ? null : (int) $row['latency_ms'],
                'available_at'    => Time::isoFromDb((string) $row['available_at']),
                'delivered_at'    => Time::isoFromDb($row['delivered_at']),
            ], $this->queue->findByEvent($eventId)),
            'attempts' => array_map(static fn (array $row): array => [
                'id'          => (int) $row['id'],
                'attempt'     => (int) $row['attempt'],
                'outcome'     => $row['outcome'],
                'status_code' => $row['status_code'] === null ? null : (int) $row['status_code'],
                'latency_ms'  => $row['latency_ms'] === null ? null : (int) $row['latency_ms'],
                'error'       => $row['error'],
                'created_at'  => Time::isoFromDb((string) $row['created_at']),
            ], $this->attempts->forEvent($eventId)),
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function eventsPage(array $filters, int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $result  = $this->events->paginateFiltered($filters, $page, $perPage);

        return [
            'rows' => array_map([$this, 'presentEventRow'], $result['rows']),
            'total'=> $result['total'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $dbOk = true;
        try {
            db_connect()->query('SELECT 1');
        } catch (Throwable) {
            $dbOk = false;
        }

        $queueCounts = ['pending' => 0, 'processing' => 0, 'delivered' => 0, 'dead' => 0];
        $dlqOpen     = 0;
        $filesTotal  = 0;
        $lastScan = null;
        $watcher  = null;
        $worker   = null;

        try {
            $queueCounts = $this->queue->counts();
            $dlqOpen     = $this->deadLetters->unresolvedCount();
            $filesTotal  = $this->files->where('enabled', 1)->countAllResults();
            $lastScan    = $this->state->get('last_scan');
            $watcher     = $this->state->get('watcher_heartbeat');
            $worker      = $this->state->get('worker_heartbeat');
        } catch (Throwable) {
            $dbOk = false;
        }
        $lastScanAge = is_array($lastScan) && isset($lastScan['at']) ? (time() - (int) strtotime((string) $lastScan['at'])) : null;
        $watcherAge  = is_array($watcher) && isset($watcher['at']) ? (time() - (int) strtotime((string) $watcher['at'])) : null;
        $workerAge   = is_array($worker) && isset($worker['at']) ? (time() - (int) strtotime((string) $worker['at'])) : null;

        $staleAfter = max(30, (int) ceil($this->config->pollIntervalMs / 1000) * 10);
        $status     = 'ok';
        $reasons    = [];

        if (! $dbOk) {
            $status     = 'error';
            $reasons[]  = 'database_unreachable';
        }

        if ($filesTotal > 0 && ($lastScanAge === null || $lastScanAge > $staleAfter)) {
            $status      = $status === 'error' ? 'error' : 'degraded';
            $reasons[]   = 'watcher_stale';
        }

        // A single delivery is bounded by the HTTP timeout; allow for it so a
        // long-running request is not mistaken for a dead worker.
        $workerStaleAfter = max(60, $this->config->httpTimeoutSec + 15);

        if ($queueCounts['pending'] > 0 && ($workerAge === null || $workerAge > $workerStaleAfter)) {
            $status    = $status === 'error' ? 'error' : 'degraded';
            $reasons[] = 'worker_stale';
        }

        return [
            'status'          => $status,
            'version'         => '1.0.0',
            'time'            => Time::isoNow(),
            'database'        => $dbOk ? 'ok' : 'error',
            'files_monitored' => $filesTotal,
            'queue'           => $queueCounts,
            'dlq_open'        => $dlqOpen,
            'last_scan_at'    => is_array($lastScan) ? ($lastScan['at'] ?? null) : null,
            'last_scan_age_s' => $lastScanAge,
            'watcher_age_s'   => $watcherAge,
            'worker_age_s'    => $workerAge,
            'reasons'         => $reasons,
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function deliveriesPage(int $page, int $perPage, ?string $status = null): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $builder = db_connect()->table('event_queue q')
            ->select('q.*, t.name AS target_name, t.type AS target_type, e.file_name, e.change_type')
            ->join('notification_targets t', 't.id = q.target_id', 'left')
            ->join('change_events e', 'e.event_id = q.event_id', 'left');

        if ($status !== null && $status !== '') {
            $builder->where('q.status', $status);
        }

        $total = (int) $builder->countAllResults(false);
        $rows  = $builder->orderBy('q.id', 'DESC')
            ->limit($perPage, max(0, ($page - 1) * $perPage))
            ->get()
            ->getResultArray();

        return ['rows' => array_map([$this, 'presentDeliveryRow'], $rows), 'total' => $total];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function presentEventRow(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'event_id'     => $row['event_id'],
            'file_name'    => $row['file_name'],
            'change_type'  => $row['change_type'],
            'summary'      => [
                'added'        => (int) $row['summary_added'],
                'updated'      => (int) $row['summary_updated'],
                'removed'      => (int) $row['summary_removed'],
                'type_changes' => (int) $row['summary_type_changes'],
            ],
            'trace_id'     => $row['trace_id'],
            'replay_count' => (int) ($row['replay_count'] ?? 0),
            'created_at'   => Time::isoFromDb((string) $row['created_at']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function presentDeliveryRow(array $row): array
    {
        return [
            'id'               => (int) $row['id'],
            'event_id'         => $row['event_id'],
            'file_name'        => $row['file_name'] ?? '—',
            'change_type'      => $row['change_type'] ?? '—',
            'target_id'        => (int) $row['target_id'],
            'target_name'      => $row['target_name'] ?? 'deleted target',
            'target_type'      => $row['target_type'] ?? 'unknown',
            'status'           => $row['status'],
            'attempts'         => (int) $row['attempts'],
            'deferrals'        => (int) $row['deferrals'],
            'last_error'       => $row['last_error'],
            'last_status_code' => $row['last_status_code'] === null ? null : (int) $row['last_status_code'],
            'latency_ms'       => $row['latency_ms'] === null ? null : (int) $row['latency_ms'],
            'available_at'     => Time::isoFromDb((string) $row['available_at']),
            'delivered_at'     => Time::isoFromDb($row['delivered_at']),
            'created_at'       => Time::isoFromDb((string) $row['created_at']),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function groupCount(object $model, string $column): array
    {
        try {
            $rows = $model->select($column . ', COUNT(*) AS total')
                ->groupBy($column)
                ->findAll();
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row[$column]] = (int) $row['total'];
        }

        return $out;
    }
}

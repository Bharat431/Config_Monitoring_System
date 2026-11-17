<?php

namespace App\Models;

use CodeIgniter\Model;

class DeliveryAttemptModel extends Model
{
    protected $table      = 'delivery_attempts';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'queue_id', 'event_id', 'target_id', 'attempt', 'outcome',
        'status_code', 'latency_ms', 'error', 'created_at',
    ];

    public function record(
        int $queueId,
        string $eventId,
        int $targetId,
        int $attempt,
        string $outcome,
        ?int $statusCode,
        ?int $latencyMs,
        ?string $error = null,
    ): bool {
        return (bool) $this->insert([
            'queue_id'    => $queueId,
            'event_id'    => $eventId,
            'target_id'   => $targetId,
            'attempt'     => $attempt,
            'outcome'     => $outcome,
            'status_code' => $statusCode,
            'latency_ms'  => $latencyMs,
            'error'       => $error,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function forEvent(string $eventId): array
    {
        return $this->where('event_id', $eventId)->orderBy('id', 'ASC')->findAll();
    }
}

<?php

namespace App\Models;

use CodeIgniter\Model;

class DeadLetterModel extends Model
{
    protected $table      = 'dead_letters';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'queue_id', 'event_id', 'target_id', 'attempts', 'reason', 'error',
        'payload', 'resolved', 'resolved_at', 'created_at',
    ];

    /** Return the outstanding dead letter for a queue row, if any. */
    public function findByQueueId(int $queueId): ?array
    {
        return $this->where('queue_id', $queueId)->where('resolved', 0)->first();
    }

    /** Find the historical dead letter for a queue row, resolved or not. */
    public function findAnyByQueueId(int $queueId): ?array
    {
        return $this->where('queue_id', $queueId)->first();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginateUnresolved(int $page = 1, int $perPage = 25): array
    {
        $builder = $this->builder()->where('resolved', 0);
        $total   = (int) $builder->countAllResults(false);
        $rows    = $builder->orderBy('id', 'DESC')
            ->limit($perPage, max(0, ($page - 1) * $perPage))
            ->get()
            ->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }

    public function resolve(int $id): bool
    {
        return (bool) $this->update($id, [
            'resolved'    => 1,
            'resolved_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function unresolvedCount(): int
    {
        return $this->where('resolved', 0)->countAllResults();
    }
}

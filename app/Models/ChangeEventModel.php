<?php

namespace App\Models;

use CodeIgniter\Model;

class ChangeEventModel extends Model
{
    protected $table      = 'change_events';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'event_id', 'monitored_file_id', 'file_name', 'file_path', 'change_type',
        'payload', 'summary_added', 'summary_updated', 'summary_removed',
        'summary_type_changes', 'hash', 'trace_id', 'error', 'replay_count', 'created_at',
    ];

    public function findByEventId(string $eventId): ?array
    {
        return $this->where('event_id', $eventId)->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 20): array
    {
        return $this->orderBy('id', 'DESC')->findAll($limit);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginateFiltered(array $filters, int $page = 1, int $perPage = 25): array
    {
        $builder = $this->builder();

        if (! empty($filters['file'])) {
            $builder->like('file_name', (string) $filters['file']);
        }
        if (! empty($filters['type'])) {
            $builder->where('change_type', (string) $filters['type']);
        }
        if (! empty($filters['since'])) {
            $builder->where('created_at >=', (string) $filters['since']);
        }
        if (! empty($filters['event_id'])) {
            $builder->where('event_id', (string) $filters['event_id']);
        }

        $total = (int) $builder->countAllResults(false);

        $rows = $builder->orderBy('id', 'DESC')
            ->limit($perPage, max(0, ($page - 1) * $perPage))
            ->get()
            ->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }

    public function incrementReplay(int $id): void
    {
        $this->builder()->set('replay_count', 'replay_count + 1', false)->where('id', $id)->update();
    }
}

<?php

namespace App\Models;

use CodeIgniter\Model;

class FileSnapshotModel extends Model
{
    protected $table      = 'file_snapshots';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'monitored_file_id', 'version', 'hash', 'size', 'parsed', 'leaf_count',
        'captured_at', 'created_at',
    ];

    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    public function latestForFile(int $fileId): ?array
    {
        return $this->where('monitored_file_id', $fileId)
            ->orderBy('version', 'DESC')
            ->first();
    }

    public function nextVersion(int $fileId): int
    {
        $row = $this->selectMax('version', 'max_version')
            ->where('monitored_file_id', $fileId)
            ->first();

        return ((int) ($row['max_version'] ?? 0)) + 1;
    }

    public function pruneForFile(int $fileId, int $keep): int
    {
        if ($keep < 1) {
            return 0;
        }

        $rows = $this->select('id')
            ->where('monitored_file_id', $fileId)
            ->orderBy('version', 'DESC')
            ->findAll();

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $drop = array_slice($ids, $keep);

        if ($drop === []) {
            return 0;
        }

        return (int) $this->whereIn('id', $drop)->delete();
    }
}

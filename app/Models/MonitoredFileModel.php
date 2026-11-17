<?php

namespace App\Models;

use CodeIgniter\Model;

class MonitoredFileModel extends Model
{
    protected $table      = 'monitored_files';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'path', 'display_name', 'extension', 'enabled', 'debounce_ms', 'status',
        'last_hash', 'current_snapshot_id', 'last_parse_ok', 'last_error', 'last_error_hash',
        'pending_hash', 'pending_since', 'pending_since_ms',
        'last_seen_at', 'last_changed_at', 'last_scan_at',
        'created_at', 'updated_at',
    ];

    public function findByPath(string $path): ?array
    {
        return $this->where('path', $path)->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabledFiles(): array
    {
        return $this->where('enabled', 1)
            ->orderBy('id', 'ASC')
            ->findAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forShard(int $shard, int $shards): array
    {
        $files = $this->enabledFiles();
        if ($shards <= 1) {
            return $files;
        }

        return array_values(array_filter(
            $files,
            static fn (array $file): bool => (crc32((string) $file['path']) % $shards) === ($shard - 1),
        ));
    }

    public function touchScan(int $id, ?string $status = null): void
    {
        $data = ['last_scan_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
        if ($status !== null) {
            $data['status'] = $status;
        }
        $this->skipValidation(true)->update($id, $data);
    }
}

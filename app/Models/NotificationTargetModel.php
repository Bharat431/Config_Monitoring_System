<?php

namespace App\Models;

use CodeIgniter\Model;

class NotificationTargetModel extends Model
{
    protected $table      = 'notification_targets';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'name', 'type', 'url', 'secret', 'token', 'headers', 'enabled',
        'last_test_at', 'last_test_ok', 'created_at', 'updated_at',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function enabled(): array
    {
        return $this->where('enabled', 1)->orderBy('id', 'ASC')->findAll();
    }

    /**
     * Remove secrets before exposing a target through the API.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function publicRow(array $row): array
    {
        $row['has_secret'] = ! empty($row['secret']);
        $row['has_token']  = ! empty($row['token']);
        unset($row['secret'], $row['token']);

        return $row;
    }
}

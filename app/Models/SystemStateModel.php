<?php

namespace App\Models;

use CodeIgniter\Model;

class SystemStateModel extends Model
{
    protected $table      = 'system_state';
    protected $primaryKey = 'state_key';
    protected $returnType = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['state_key', 'state_value', 'updated_at'];

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->find($key);
        if ($row === null) {
            return $default;
        }

        $decoded = json_decode((string) $row['state_value'], true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $row['state_value'];
    }

    public function put(string $key, mixed $value): void
    {
        $payload = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $now     = gmdate('Y-m-d H:i:s');

        $existing = $this->find($key);
        if ($existing === null) {
            $this->insert([
                'state_key'   => $key,
                'state_value' => $payload,
                'updated_at'  => $now,
            ]);

            return;
        }

        $this->update($key, ['state_value' => $payload, 'updated_at' => $now]);
    }

    public function forget(string $key): void
    {
        $this->delete($key);
    }
}

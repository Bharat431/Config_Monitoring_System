<?php

namespace App\Models;

use CodeIgniter\Model;

class MetricCounterModel extends Model
{
    protected $table      = 'metric_counters';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = ['name', 'labels_hash', 'labels', 'value', 'updated_at'];

    public function findByNameAndHash(string $name, string $hash): ?array
    {
        return $this->where('name', $name)->where('labels_hash', $hash)->first();
    }

    public function add(string $name, string $hash, string $labelsJson, float $by): void
    {
        $row = $this->findByNameAndHash($name, $hash);
        $now = gmdate('Y-m-d H:i:s');

        if ($row === null) {
            try {
                $this->insert([
                    'name'        => $name,
                    'labels_hash' => $hash,
                    'labels'      => $labelsJson,
                    'value'       => $by,
                    'updated_at'  => $now,
                ]);

                return;
            } catch (\Throwable) {
                // Concurrent insert: fall through to the atomic update.
            }
        }

        $this->db->table($this->table)
            ->where('name', $name)
            ->where('labels_hash', $hash)
            ->set('value', 'value + ' . (float) $by, false)
            ->set('updated_at', $now)
            ->update();
    }

    public function setValue(string $name, string $hash, string $labelsJson, float $value): void
    {
        $row = $this->findByNameAndHash($name, $hash);
        $now = gmdate('Y-m-d H:i:s');

        if ($row === null) {
            try {
                $this->insert([
                    'name'        => $name,
                    'labels_hash' => $hash,
                    'labels'      => $labelsJson,
                    'value'       => $value,
                    'updated_at'  => $now,
                ]);

                return;
            } catch (\Throwable) {
                // Fall through.
            }
        }

        $this->db->table($this->table)
            ->where('name', $name)
            ->where('labels_hash', $hash)
            ->update(['value' => $value, 'updated_at' => $now]);
    }

    public function allRows(): array
    {
        return $this->orderBy('name', 'ASC')->findAll();
    }
}

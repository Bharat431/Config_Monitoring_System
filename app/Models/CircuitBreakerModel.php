<?php

namespace App\Models;

use CodeIgniter\Model;

class CircuitBreakerModel extends Model
{
    protected $table      = 'circuit_breakers';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'target_id', 'state', 'failures', 'opened_at', 'next_attempt_at',
        'last_error', 'updated_at',
    ];

    public function forTarget(int $targetId): ?array
    {
        return $this->where('target_id', $targetId)->first();
    }

    public function stateFor(int $targetId): string
    {
        return (string) ($this->forTarget($targetId)['state'] ?? 'closed');
    }

    public function openCount(): int
    {
        return $this->where('state', 'open')->countAllResults();
    }
}

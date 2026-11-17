<?php

namespace App\Models;

use CodeIgniter\Model;

class AuditLogModel extends Model
{
    protected $table      = 'audit_logs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['actor', 'action', 'subject', 'context', 'ip_address', 'created_at'];

    public function record(
        string $action,
        ?string $subject = null,
        array $context = [],
        ?string $actor = 'system',
        ?string $ip = null,
    ): void {
        $this->insert([
            'actor'      => $actor,
            'action'     => $action,
            'subject'    => $subject,
            'context'    => $context === [] ? null : json_encode($context, JSON_UNESCAPED_SLASHES),
            'ip_address' => $ip,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}

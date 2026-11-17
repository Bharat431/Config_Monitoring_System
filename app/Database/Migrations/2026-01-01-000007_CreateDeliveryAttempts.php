<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDeliveryAttempts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'queue_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'event_id' => ['type' => 'VARCHAR', 'constraint' => 36],
            'target_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'attempt' => ['type' => 'INT', 'constraint' => 11, 'default' => 1],
            'outcome' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'failure'],
            'status_code' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'latency_ms' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'error' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('queue_id');
        $this->forge->addKey('event_id');
        $this->forge->addKey('created_at');
        $this->forge->createTable('delivery_attempts', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('delivery_attempts', true);
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateEventQueue extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'event_id' => ['type' => 'VARCHAR', 'constraint' => 36],
            'target_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'attempts' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'deferrals' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'available_at' => ['type' => 'DATETIME'],
            'locked_at' => ['type' => 'DATETIME', 'null' => true],
            'locked_by' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'delivered_at' => ['type' => 'DATETIME', 'null' => true],
            'last_error' => ['type' => 'TEXT', 'null' => true],
            'last_status_code' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'latency_ms' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['event_id', 'target_id']);
        $this->forge->addKey(['status', 'available_at']);
        $this->forge->addKey('target_id');
        $this->forge->addKey('created_at');
        $this->forge->createTable('event_queue', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('event_queue', true);
    }
}

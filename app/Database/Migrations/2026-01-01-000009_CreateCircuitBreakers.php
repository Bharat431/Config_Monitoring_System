<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCircuitBreakers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'target_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'state' => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'closed'],
            'failures' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'opened_at' => ['type' => 'DATETIME', 'null' => true],
            'next_attempt_at' => ['type' => 'DATETIME', 'null' => true],
            'last_error' => ['type' => 'TEXT', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('target_id');
        $this->forge->createTable('circuit_breakers', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('circuit_breakers', true);
    }
}

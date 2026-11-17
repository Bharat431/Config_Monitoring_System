<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDeadLetters extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'queue_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'event_id' => ['type' => 'VARCHAR', 'constraint' => 36],
            'target_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'attempts' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'reason' => ['type' => 'VARCHAR', 'constraint' => 120, 'default' => 'max_attempts_exceeded'],
            'error' => ['type' => 'TEXT', 'null' => true],
            'payload' => ['type' => 'MEDIUMTEXT'],
            'resolved' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'resolved_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('queue_id');
        $this->forge->addKey('event_id');
        $this->forge->addKey('resolved');
        $this->forge->addKey('created_at');
        $this->forge->createTable('dead_letters', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('dead_letters', true);
    }
}

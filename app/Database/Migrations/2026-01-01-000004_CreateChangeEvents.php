<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateChangeEvents extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'event_id' => ['type' => 'VARCHAR', 'constraint' => 36],
            'monitored_file_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'file_name' => ['type' => 'VARCHAR', 'constraint' => 255],
            'file_path' => ['type' => 'VARCHAR', 'constraint' => 512],
            'change_type' => ['type' => 'VARCHAR', 'constraint' => 24],
            'payload' => ['type' => 'MEDIUMTEXT'],
            'summary_added' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'summary_updated' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'summary_removed' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'summary_type_changes' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'hash' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'trace_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'error' => ['type' => 'TEXT', 'null' => true],
            'replay_count' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('event_id');
        $this->forge->addKey('created_at');
        $this->forge->addKey('change_type');
        $this->forge->addKey('monitored_file_id');
        $this->forge->addKey('file_name');
        $this->forge->createTable('change_events', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('change_events', true);
    }
}

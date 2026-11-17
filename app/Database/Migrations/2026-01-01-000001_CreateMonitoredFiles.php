<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMonitoredFiles extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'path' => ['type' => 'VARCHAR', 'constraint' => 512],
            'display_name' => ['type' => 'VARCHAR', 'constraint' => 255],
            'extension' => ['type' => 'VARCHAR', 'constraint' => 16],
            'enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'debounce_ms' => ['type' => 'INT', 'constraint' => 11, 'default' => 750],
            'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'pending'],
            'last_hash' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'current_snapshot_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'last_parse_ok' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'last_error' => ['type' => 'TEXT', 'null' => true],
            'last_error_hash' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'pending_hash' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'pending_since' => ['type' => 'DATETIME', 'null' => true],
            'last_seen_at' => ['type' => 'DATETIME', 'null' => true],
            'last_changed_at' => ['type' => 'DATETIME', 'null' => true],
            'last_scan_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('path');
        $this->forge->addKey('status');
        $this->forge->addKey('enabled');
        $this->forge->createTable('monitored_files', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('monitored_files', true);
    }
}

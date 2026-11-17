<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateFileSnapshots extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'monitored_file_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'version' => ['type' => 'INT', 'constraint' => 11, 'default' => 1],
            'hash' => ['type' => 'VARCHAR', 'constraint' => 64],
            'size' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'parsed' => ['type' => 'MEDIUMTEXT'],
            'leaf_count' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'captured_at' => ['type' => 'DATETIME'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('monitored_file_id');
        $this->forge->addUniqueKey(['monitored_file_id', 'version']);
        $this->forge->addKey('created_at');
        $this->forge->createTable('file_snapshots', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('file_snapshots', true);
    }
}

<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSystemState extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'state_key' => ['type' => 'VARCHAR', 'constraint' => 100],
            'state_value' => ['type' => 'TEXT', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('state_key', true);
        $this->forge->createTable('system_state', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('system_state', true);
    }
}

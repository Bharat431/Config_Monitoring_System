<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateNotificationTargets extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'type' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'webhook'],
            'url' => ['type' => 'TEXT'],
            'secret' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'token' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'headers' => ['type' => 'TEXT', 'null' => true],
            'enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'last_test_at' => ['type' => 'DATETIME', 'null' => true],
            'last_test_ok' => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('enabled');
        $this->forge->createTable('notification_targets', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('notification_targets', true);
    }
}

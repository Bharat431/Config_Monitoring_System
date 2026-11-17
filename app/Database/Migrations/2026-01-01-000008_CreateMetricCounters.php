<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMetricCounters extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'labels_hash' => ['type' => 'VARCHAR', 'constraint' => 32],
            'labels' => ['type' => 'TEXT', 'null' => true],
            'value' => ['type' => 'DOUBLE', 'default' => 0],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['name', 'labels_hash']);
        $this->forge->addKey('name');
        $this->forge->createTable('metric_counters', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('metric_counters', true);
    }
}

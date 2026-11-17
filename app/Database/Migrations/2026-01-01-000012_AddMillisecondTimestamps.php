<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Second-resolution DATETIME columns cannot express sub-second debounce and
 * retry windows. These epoch-millisecond columns are the authoritative
 * scheduling values; the DATETIME columns remain for human inspection.
 */
class AddMillisecondTimestamps extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('pending_since_ms', 'monitored_files')) {
            $this->forge->addColumn('monitored_files', [
                'pending_since_ms' => ['type' => 'BIGINT', 'null' => true, 'default' => null],
            ]);
        }

        if (! $this->db->fieldExists('available_at_ms', 'event_queue')) {
            $this->forge->addColumn('event_queue', [
                'available_at_ms' => ['type' => 'BIGINT', 'null' => true, 'default' => null],
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('pending_since_ms', 'monitored_files')) {
            $this->forge->dropColumn('monitored_files', 'pending_since_ms');
        }

        if ($this->db->fieldExists('available_at_ms', 'event_queue')) {
            $this->forge->dropColumn('event_queue', 'available_at_ms');
        }
    }
}

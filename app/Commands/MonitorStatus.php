<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Prints a human readable snapshot of system health, queue depth and recent
 * activity. Useful from cron/alerting and during development.
 */
class MonitorStatus extends BaseCommand
{
    protected $group       = 'Config Monitor';
    protected $name        = 'monitor:status';
    protected $description = 'Show health, queue, delivery and watcher status.';
    protected $usage       = 'monitor:status [--json]';
    protected $arguments   = [];
    protected $options     = [
        '--json' => 'Print a single JSON document instead of tables.',
    ];

    public function run(array $params)
    {
        $stats = service('stats');

        try {
            $health   = $stats->health();
            $overview = $stats->overview();
        } catch (Throwable $e) {
            CLI::error('Unable to read status: ' . $e->getMessage());
            CLI::write('Check that the database is migrated (php spark migrate).', 'yellow');

            return EXIT_ERROR;
        }

        if ((bool) CLI::getOption('json')) {
            CLI::write(json_encode([
                'health'   => $health,
                'overview' => $overview,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return EXIT_SUCCESS;
        }

        $statusColor = match ($health['status']) {
            'ok'       => 'green',
            'degraded' => 'yellow',
            default    => 'red',
        };

        CLI::write('Health', 'yellow');
        CLI::write('  status          : ' . CLI::color((string) $health['status'], $statusColor));
        CLI::write('  database        : ' . $health['database']);
        CLI::write('  files monitored : ' . $health['files_monitored']);
        CLI::write('  dlq open        : ' . $health['dlq_open']);
        CLI::write('  last scan       : ' . ($health['last_scan_at'] ?? 'never') . ($health['last_scan_age_s'] !== null ? sprintf(' (%ds ago)', $health['last_scan_age_s']) : ''));
        CLI::write('  watcher age     : ' . ($health['watcher_age_s'] !== null ? $health['watcher_age_s'] . 's' : 'never'));
        CLI::write('  worker age      : ' . ($health['worker_age_s'] !== null ? $health['worker_age_s'] . 's' : 'never'));
        if (($health['reasons'] ?? []) !== []) {
            CLI::write('  reasons         : ' . implode(', ', $health['reasons']), 'yellow');
        }
        CLI::newLine();

        $queue = $health['queue'];
        CLI::write('Queue', 'yellow');
        CLI::table([
            ['pending', $queue['pending']],
            ['processing', $queue['processing']],
            ['delivered', $queue['delivered']],
            ['dead', $queue['dead']],
        ], ['State', 'Count']);
        CLI::newLine();

        $notifications = $overview['notifications'];
        CLI::write('Deliveries', 'yellow');
        CLI::table([
            ['success rate', $notifications['success_rate'] === null ? 'n/a' : $notifications['success_rate'] . '%'],
            ['avg latency', $notifications['avg_latency_ms'] === null ? 'n/a' : $notifications['avg_latency_ms'] . ' ms'],
            ['attempts total', $notifications['attempts_total']],
            ['attempts failed', $notifications['attempts_failed']],
            ['circuits open', $overview['health']['circuit_open']],
        ], ['Metric', 'Value']);
        CLI::newLine();

        $files = $overview['files'];
        CLI::write('Files', 'yellow');
        CLI::table([
            ['total', $files['total']],
            ['active', $files['active']],
            ['error', $files['error']],
            ['paused', $files['paused']],
        ], ['State', 'Count']);
        CLI::newLine();

        CLI::write('Events', 'yellow');
        CLI::write('  total     : ' . $overview['events']['total']);
        CLI::write('  last 24h  : ' . $overview['events']['last_24h']);

        $recent = $overview['events']['recent'];
        if ($recent !== []) {
            CLI::newLine();
            CLI::write('Recent events', 'yellow');
            $rows = [];
            foreach (array_slice($recent, 0, 8) as $event) {
                $rows[] = [
                    $event['created_at'] ?? '',
                    $event['change_type'] ?? '',
                    $event['file_name'] ?? '',
                    $event['event_id'] ?? '',
                ];
            }
            CLI::table($rows, ['Time', 'Type', 'File', 'Event']);
        }

        return EXIT_SUCCESS;
    }
}

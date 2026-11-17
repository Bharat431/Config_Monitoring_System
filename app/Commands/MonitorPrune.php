<?php

namespace App\Commands;

use App\Support\CliOption;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Retention housekeeping. Removes delivered/dead queue rows, old events,
 * delivery attempts, resolved dead letters, metrics and audit entries that are
 * older than the retention window.
 *
 * Run it from cron, e.g.: 0 3 * * * php spark monitor:prune
 */
class MonitorPrune extends BaseCommand
{
    protected $group       = 'Config Monitor';
    protected $name        = 'monitor:prune';
    protected $description = 'Delete data older than the retention window.';
    protected $usage       = 'monitor:prune [--days 30] [--dry-run]';
    protected $arguments   = [];
    protected $options     = [
        '--days'    => 'Retention window in days (defaults to CONFIGMON_RETENTION_DAYS).',
        '--dry-run' => 'Count rows that would be deleted without deleting.',
    ];

    public function run(array $params)
    {
        $config = service('monitorConfig');
        $days   = max(1, CliOption::int('days', $config->retentionDays));
        $dryRun = CliOption::flag('dry-run');
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        CLI::write(sprintf(
            '%s data older than %s (%d days)%s',
            $dryRun ? 'Inspecting' : 'Pruning',
            $cutoff,
            $days,
            $dryRun ? ' [dry run]' : '',
        ), 'yellow');
        CLI::newLine();

        $db = db_connect();

        // table => [where clauses]
        $plan = [
            'event_queue'        => [['created_at <', $cutoff], ['status', ['delivered', 'dead']]],
            'change_events'      => [['created_at <', $cutoff]],
            'delivery_attempts'  => [['created_at <', $cutoff]],
            'dead_letters'       => [['created_at <', $cutoff], ['resolved', 1]],
            'metric_counters'    => [['updated_at <', $cutoff]],
            'audit_logs'         => [['created_at <', $cutoff]],
        ];

        $rows     = [];
        $deleted  = 0;
        $exit     = EXIT_SUCCESS;

        foreach ($plan as $table => $conditions) {
            try {
                $builder = $db->table($table);
                foreach ($conditions as [$column, $value]) {
                    if (is_array($value)) {
                        $builder->whereIn($column, $value);
                    } else {
                        $builder->where($column, $value);
                    }
                }

                $count = (int) $builder->countAllResults();

                if (! $dryRun && $count > 0) {
                    $delete = $db->table($table);
                    foreach ($conditions as [$column, $value]) {
                        if (is_array($value)) {
                            $delete->whereIn($column, $value);
                        } else {
                            $delete->where($column, $value);
                        }
                    }
                    $delete->delete();
                    $deleted += $count;
                }

                $rows[] = [$table, $count, $dryRun ? 'would delete' : 'deleted'];
            } catch (Throwable $e) {
                $exit   = EXIT_ERROR;
                $rows[] = [$table, 'error', $e->getMessage()];
            }
        }

        CLI::table($rows, ['Table', 'Rows', 'Result']);

        if ($dryRun) {
            CLI::write('Dry run complete. No rows were changed.', 'green');
        } else {
            CLI::write(sprintf('Pruned %d row(s).', $deleted), 'green');
        }

        return $exit;
    }
}

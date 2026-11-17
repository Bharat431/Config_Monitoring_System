<?php

namespace App\Commands;

use App\Support\CliOption;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * One-shot scan. Useful for cron-based deployments, CI smoke tests and for
 * recording a baseline after adding files.
 */
class MonitorScan extends BaseCommand
{
    protected $group       = 'Config Monitor';
    protected $name        = 'monitor:scan';
    protected $description = 'Run a single scan cycle and print the result.';
    protected $usage       = 'monitor:scan [--baseline] [--shard 1] [--shards 1] [--json]';
    protected $arguments   = [];
    protected $options     = [
        '--baseline' => 'Record the current state as baseline without emitting events.',
        '--shard'    => 'Shard number to scan (1-based).',
        '--shards'   => 'Total number of watcher shards.',
        '--json'     => 'Print the raw result as JSON.',
    ];

    public function run(array $params)
    {
        $config  = service('monitorConfig');
        $watcher = service('watcherService');

        $baseline = CliOption::flag('baseline');
        $shards   = max(1, CliOption::int('shards', $config->watcherShards));
        $shard    = max(1, min($shards, CliOption::int('shard', $config->watcherShard)));

        $started = microtime(true);

        try {
            $counts = $watcher->scanAll($baseline, $shard, $shards);
        } catch (\Throwable $e) {
            CLI::error('Scan failed: ' . $e->getMessage());

            return EXIT_ERROR;
        }

        if (CliOption::flag('json')) {
            CLI::write(json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return EXIT_SUCCESS;
        }

        $rows = [];
        foreach ($counts as $key => $value) {
            $rows[] = [ucfirst(str_replace('_', ' ', $key)), $value];
        }

        CLI::table($rows, ['Outcome', 'Count']);
        CLI::write(sprintf(
            'Scan complete in %.2fs (shard %d/%d%s).',
            microtime(true) - $started,
            $shard,
            $shards,
            $baseline ? ', baseline' : '',
        ), 'green');

        return EXIT_SUCCESS;
    }
}

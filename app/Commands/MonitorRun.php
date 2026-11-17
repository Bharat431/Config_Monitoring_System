<?php

namespace App\Commands;

use App\Support\CliOption;
use App\Support\StopSignal;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Long-lived watcher daemon.
 *
 * Polls every registered file, debounces rapid writes, parses, diffs and
 * publishes change events to the queue. Multiple instances can run in parallel
 * by using `--shard`/`--shards` (consistent hashing on the file path).
 */
class MonitorRun extends BaseCommand
{
    protected $group       = 'Config Monitor';
    protected $name        = 'monitor:run';
    protected $description = 'Run the configuration file watcher daemon.';
    protected $usage       = 'monitor:run [--once] [--baseline] [--shard 1] [--shards 1]';
    protected $arguments   = [];
    protected $options     = [
        '--once'     => 'Run a single scan cycle and exit.',
        '--baseline' => 'Record the current state as baseline without emitting events.',
        '--shard'    => 'Shard number to process (1-based).',
        '--shards'   => 'Total number of watcher shards.',
    ];

    public function run(array $params)
    {
        $config  = service('monitorConfig');
        $watcher = service('watcherService');
        $logger  = service('jsonLogger');

        $once     = CliOption::flag('once');
        $baseline = CliOption::flag('baseline');
        $shards   = (int) (CliOption::get('shards') ?: $config->watcherShards);
        $shard    = (int) (CliOption::get('shard') ?: $config->watcherShard);

        $shards = max(1, $shards);
        $shard  = max(1, min($shards, $shard));

        CLI::write('Configuration watcher starting', 'yellow');
        CLI::write(sprintf(
            '  roots : %s',
            implode(', ', $config->watchRoots()),
        ));
        CLI::write(sprintf('  poll  : %d ms  debounce: %d ms', $config->pollIntervalMs, $config->debounceMs));
        CLI::write(sprintf('  shard : %d/%d  pid: %d', $shard, $shards, getmypid()));
        CLI::write(sprintf('  stop  : create %s to stop gracefully', StopSignal::stopFile()));
        CLI::newLine();

        StopSignal::install();

        if (! $once) {
            // Remove a stale stop file from a previous run so the daemon starts.
            StopSignal::clear();
        }

        $logger->info('Watcher daemon started', [
            'component' => 'watcher',
            'pid'       => getmypid(),
            'once'      => $once,
            'shard'     => $shard,
            'shards'    => $shards,
            'baseline'  => $baseline,
        ]);

        $cycle = 0;
        $exit  = EXIT_SUCCESS;

        do {
            $cycle++;
            $started = microtime(true);

            try {
                $counts = $watcher->scanAll($baseline, $shard, $shards);
                $this->reportCycle($cycle, $counts, microtime(true) - $started);
            } catch (Throwable $e) {
                $exit = EXIT_ERROR;
                $logger->error('Watcher cycle failed', [
                    'component' => 'watcher',
                    'cycle'     => $cycle,
                    'error'     => $e,
                ]);
                CLI::error(sprintf('Cycle %d failed: %s', $cycle, $e->getMessage()));

                if ($once) {
                    break;
                }
            }

            if ($once) {
                break;
            }

            if (StopSignal::isRequested()) {
                break;
            }

            $elapsedMs = (int) round((microtime(true) - $started) * 1000);
            $sleepMs   = max(100, $config->pollIntervalMs - $elapsedMs);

            usleep($sleepMs * 1000);
        } while (! StopSignal::isRequested());

        CLI::newLine();
        CLI::write('Watcher stopped gracefully.', 'green');
        $logger->info('Watcher daemon stopped', [
            'component' => 'watcher',
            'cycles'    => $cycle,
        ]);

        return $exit;
    }

    /**
     * @param array<string, int> $counts
     */
    private function reportCycle(int $cycle, array $counts, float $seconds): void
    {
        $changed = ($counts['created'] ?? 0) + ($counts['modified'] ?? 0) + ($counts['deleted'] ?? 0) + ($counts['parse_error'] ?? 0);

        $line = sprintf(
            '[%s] cycle %d  scanned=%d unchanged=%d debounced=%d changed=%d missing=%d failed=%d  %.2fs',
            gmdate('H:i:s'),
            $cycle,
            $counts['scanned'] ?? 0,
            $counts['unchanged'] ?? 0,
            $counts['debounced'] ?? 0,
            $changed,
            $counts['missing'] ?? 0,
            $counts['failed'] ?? 0,
            $seconds,
        );

        if (($counts['failed'] ?? 0) > 0 || ($counts['parse_error'] ?? 0) > 0) {
            CLI::write($line, 'yellow');
        } elseif ($changed > 0) {
            CLI::write($line, 'green');
        } else {
            CLI::write($line, 'dark_gray');
        }
    }
}

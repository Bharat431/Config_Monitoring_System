<?php

namespace App\Commands;

use App\Support\CliOption;
use App\Support\StopSignal;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Queue consumer daemon.
 *
 * Claims pending delivery jobs, performs webhook/Slack/Discord/Teams delivery
 * and applies retry, backoff, DLQ and circuit-breaker policies.
 */
class MonitorWork extends BaseCommand
{
    protected $group       = 'Config Monitor';
    protected $name        = 'monitor:work';
    protected $description = 'Run the notification queue worker (delivers webhooks).';
    protected $usage       = 'monitor:work [--once] [--max-batches 0] [--sleep 500]';
    protected $arguments   = [];
    protected $options     = [
        '--once'        => 'Process one batch and exit.',
        '--max-batches' => 'Stop after N batches (0 = unlimited).',
        '--sleep'       => 'Idle sleep in milliseconds when the queue is empty.',
    ];

    public function run(array $params)
    {
        $config = service('monitorConfig');
        $worker = service('queueWorker');
        $logger = service('jsonLogger');

        $once       = CliOption::flag('once');
        $maxBatches = max(0, CliOption::int('max-batches'));
        $idleSleep  = max(100, CliOption::int('sleep', 500));

        CLI::write('Notification queue worker starting', 'yellow');
        CLI::write(sprintf('  worker : %s', $worker->workerId()));
        CLI::write(sprintf('  batch  : %d  max attempts: %d', $config->queueBatchSize, $config->queueMaxAttempts));
        CLI::write(sprintf('  stop   : create %s to stop gracefully', StopSignal::stopFile()));
        CLI::newLine();

        StopSignal::install();
        if (! $once) {
            StopSignal::clear();
        }

        $logger->info('Queue worker started', [
            'component' => 'worker',
            'worker_id' => $worker->workerId(),
            'once'      => $once,
        ]);

        $batches   = 0;
        $processed = 0;
        $reaped    = 0;
        $count     = 0;
        $exit      = EXIT_SUCCESS;
        $started   = microtime(true);

        do {
            // Recover jobs whose worker crashed mid-delivery before claiming new
            // work, so `--once` runs also pick up orphaned jobs.
            try {
                $recovered = $worker->reapStale();
                $reaped += $recovered;
                if ($recovered > 0) {
                    CLI::write(sprintf('Requeued %d stale job(s).', $recovered), 'yellow');
                }
            } catch (Throwable $e) {
                $logger->warn('Queue stale reaper failed', [
                    'component' => 'worker',
                    'error'     => $e,
                ]);
            }

            try {
                $count = $worker->processBatch($config->queueBatchSize);
                $processed += $count;
                $batches++;

                if ($count > 0) {
                    CLI::write(sprintf('[%s] batch %d delivered attempt for %d job(s), total %d', gmdate('H:i:s'), $batches, $count, $processed), 'green');
                }
            } catch (Throwable $e) {
                $exit = EXIT_ERROR;
                $logger->error('Queue worker batch failed', [
                    'component' => 'worker',
                    'error'     => $e,
                ]);
                CLI::error(sprintf('Batch %d failed: %s', $batches + 1, $e->getMessage()));

                if ($once) {
                    break;
                }
            }

            if ($once || ($maxBatches > 0 && $batches >= $maxBatches)) {
                break;
            }

            if (StopSignal::isRequested()) {
                break;
            }

            // Back off when idle to avoid a hot loop, drain immediately otherwise.
            if ($count === 0) {
                usleep($idleSleep * 1000);
            }
        } while (! StopSignal::isRequested());

        CLI::newLine();
        CLI::write(sprintf('Worker stopped. batches=%d delivered=%d reaped=%d elapsed=%.1fs', $batches, $processed, $reaped, microtime(true) - $started), 'green');

        $logger->info('Queue worker stopped', [
            'component' => 'worker',
            'worker_id' => $worker->workerId(),
            'batches'   => $batches,
            'processed' => $processed,
            'reaped'    => $reaped,
        ]);

        return $exit;
    }
}

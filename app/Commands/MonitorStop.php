<?php

namespace App\Commands;

use App\Support\CliOption;
use App\Support\StopSignal;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Requests a graceful shutdown of running watcher/worker daemons.
 *
 * On Linux/macOS containers SIGTERM is preferred; this command uses the
 * cross-platform stop-file fallback that both daemons poll between cycles.
 */
class MonitorStop extends BaseCommand
{
    protected $group       = 'Config Monitor';
    protected $name        = 'monitor:stop';
    protected $description = 'Ask running monitor daemons to stop gracefully.';
    protected $usage       = 'monitor:stop [--clear]';
    protected $arguments   = [];
    protected $options     = [
        '--clear' => 'Remove a pending stop request instead of creating one.',
    ];

    public function run(array $params)
    {
        if (CliOption::flag('clear')) {
            StopSignal::clear();
            CLI::write('Stop request cleared. Daemons may continue running.', 'green');

            return EXIT_SUCCESS;
        }

        StopSignal::requestSoftly();
        CLI::write('Stop requested. Daemons exit after their current cycle.', 'green');
        CLI::write('  stop file: ' . StopSignal::stopFile());

        return EXIT_SUCCESS;
    }
}

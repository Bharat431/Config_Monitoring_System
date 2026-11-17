<?php

namespace App\Commands;

use App\Support\CliOption;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Bootstraps the demo environment and simulates configuration changes.
 *
 *   php spark monitor:demo                  # seed fixtures + watches + target
 *   php spark monitor:demo reset            # rewrite fixtures from scratch
 *   php spark monitor:demo simulate         # apply a random update
 *   php spark monitor:demo simulate delete  # apply a specific change kind
 *   php spark monitor:demo simulate --file app.yaml --kind type_change
 */
class MonitorDemo extends BaseCommand
{
    protected $group       = 'Config Monitor';
    protected $name        = 'monitor:demo';
    protected $description = 'Seed the demo environment or simulate a config change.';
    protected $usage       = 'monitor:demo [seed|reset|simulate] [kind] [--file name] [--kind kind]';
    protected $arguments   = [
        'action' => 'seed (default), reset or simulate.',
        'kind'   => 'Change kind for simulate: update, type_change, delete, create, parse_error.',
    ];
    protected $options     = [
        '--file' => 'Demo file name to mutate (e.g. app.yaml).',
        '--kind' => 'Change kind for simulate (alternative to the positional argument).',
    ];

    private const KINDS = ['update', 'type_change', 'delete', 'create', 'parse_error'];

    public function run(array $params)
    {
        $demo = service('demo');

        $action = strtolower(trim((string) ($params[0] ?? 'seed')));
        if ($action === '') {
            $action = 'seed';
        }

        try {
            if (! in_array($action, ['seed', 'reset', 'simulate'], true)) {
                CLI::error('Unknown action "' . $action . '". Use seed, reset or simulate.');

                return EXIT_USER_INPUT;
            }

            if ($action === 'simulate') {
                return $this->simulate($demo, $params);
            }

            $result = $demo->seed($action === 'reset');

            CLI::write($action === 'reset' ? 'Demo environment reset.' : 'Demo environment ready.', 'green');
            CLI::write('  directory : ' . $result['directory']);
            CLI::write('  written   : ' . (count($result['written']) > 0 ? implode(', ', $result['written']) : '(existing fixtures kept)'));
            CLI::write('  watching  : ' . count($result['watched']) . ' file(s)');
            foreach ($result['watched'] as $watch) {
                CLI::write(sprintf('    - %s (%s)', $watch['file'], $watch['baseline']));
            }

            $target = $result['target'] ?? [];
            if (isset($target['target']['id'])) {
                CLI::write(sprintf(
                    '  target    : #%d %s -> %s',
                    $target['target']['id'],
                    $target['target']['name'] ?? 'target',
                    $target['target']['url'] ?? '',
                ));
            }

            CLI::newLine();
            CLI::write('Next steps:', 'yellow');
            CLI::write('  1. php spark serve                                # API + dashboard on http://localhost:8080');
            CLI::write('  2. php spark monitor:run                          # watcher daemon');
            CLI::write('  3. php spark monitor:work                         # queue worker');
            CLI::write('  4. php spark monitor:demo simulate                # emit a change');

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function simulate(object $demo, array $params): int
    {
        $kind = strtolower(trim((string) (CliOption::get('kind') ?: ($params[1] ?? 'update'))));
        $file = CliOption::get('file');
        $file = is_string($file) && $file !== '' ? $file : null;

        if (! in_array($kind, self::KINDS, true)) {
            CLI::error('Unknown kind "' . $kind . '". Use: ' . implode(', ', self::KINDS) . '.');

            return EXIT_USER_INPUT;
        }

        $result = $demo->simulateChange($file, $kind);

        CLI::write(sprintf('Simulated %s on %s', $kind, $result['file'] ?? '?'), 'green');

        foreach ($result['events'] ?? [] as $event) {
            CLI::write(sprintf('  -> %s event %s', $event['type'] ?? '?', $event['event_id'] ?? '?'));
        }

        if (($result['events'] ?? []) === []) {
            CLI::write('  (no new change event; file content may be unchanged)', 'yellow');
        }

        return EXIT_SUCCESS;
    }
}

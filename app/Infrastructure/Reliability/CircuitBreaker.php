<?php

namespace App\Infrastructure\Reliability;

use App\Infrastructure\Logging\JsonLogger;
use App\Models\CircuitBreakerModel;
use App\Support\Time;
use Config\Monitor;

/**
 * Per-target circuit breaker.
 *
 * closed -> open after N consecutive failures
 * open   -> half_open after cooldown, one trial delivery is allowed
 * success -> closed, failure in half_open -> open again
 */
class CircuitBreaker
{
    public function __construct(
        private readonly Monitor $config,
        private readonly CircuitBreakerModel $model,
        private readonly JsonLogger $logger,
    ) {
    }

    /**
     * @return array{allowed: bool, state: string, retry_at: ?string}
     */
    public function check(int $targetId): array
    {
        $row = $this->model->forTarget($targetId);

        if ($row === null || $row['state'] === 'closed') {
            return ['allowed' => true, 'state' => 'closed', 'retry_at' => null];
        }

        if ($row['state'] === 'half_open') {
            return ['allowed' => true, 'state' => 'half_open', 'retry_at' => null];
        }

        $nextAttempt = (string) ($row['next_attempt_at'] ?? '');
        $now         = Time::dbNow();

        if ($nextAttempt !== '' && $nextAttempt <= $now) {
            $this->model->update($row['id'], [
                'state'      => 'half_open',
                'updated_at' => $now,
            ]);

            $this->logger->info('Circuit breaker half-open trial', [
                'component' => 'circuit_breaker',
                'target_id' => $targetId,
            ]);

            return ['allowed' => true, 'state' => 'half_open', 'retry_at' => null];
        }

        return ['allowed' => false, 'state' => 'open', 'retry_at' => $nextAttempt ?: null];
    }

    public function recordSuccess(int $targetId): void
    {
        $row = $this->model->forTarget($targetId);
        $now = Time::dbNow();

        if ($row === null) {
            $this->model->insert([
                'target_id'      => $targetId,
                'state'          => 'closed',
                'failures'       => 0,
                'opened_at'      => null,
                'next_attempt_at'=> null,
                'last_error'     => null,
                'updated_at'     => $now,
            ]);

            return;
        }

        if ($row['state'] !== 'closed' || (int) $row['failures'] !== 0) {
            $this->logger->info('Circuit breaker closed', [
                'component' => 'circuit_breaker',
                'target_id' => $targetId,
            ]);
        }

        $this->model->update($row['id'], [
            'state'           => 'closed',
            'failures'        => 0,
            'opened_at'       => null,
            'next_attempt_at' => null,
            'last_error'      => null,
            'updated_at'      => $now,
        ]);
    }

    public function recordFailure(int $targetId, string $error): void
    {
        $row      = $this->model->forTarget($targetId);
        $now      = Time::dbNow();
        $failures = $row === null ? 1 : ((int) $row['failures']) + 1;
        $state    = $row['state'] ?? 'closed';

        $shouldOpen = $state === 'half_open'
            || $failures >= max(1, $this->config->circuitFailureThreshold);

        $data = [
            'target_id'  => $targetId,
            'state'      => $shouldOpen ? 'open' : 'closed',
            'failures'   => $failures,
            'last_error' => mb_substr($error, 0, 1000),
            'updated_at' => $now,
        ];

        if ($shouldOpen) {
            $data['opened_at']       = $now;
            $data['next_attempt_at'] = Time::dbAfter(max(1, $this->config->circuitCooldownSec));

            $this->logger->warn('Circuit breaker opened', [
                'component' => 'circuit_breaker',
                'target_id' => $targetId,
                'failures'  => $failures,
                'cooldown'  => $this->config->circuitCooldownSec,
            ]);
        }

        if ($row === null) {
            $this->model->insert($data);

            return;
        }

        $this->model->update($row['id'], $data);
    }

    public function state(int $targetId): string
    {
        return (string) ($this->model->forTarget($targetId)['state'] ?? 'closed');
    }
}

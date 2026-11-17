<?php

namespace App\Controllers\Api;

use App\Controllers\BaseApiController;
use App\Models\NotificationTargetModel;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Demo extends BaseApiController
{
    public function seed(): ResponseInterface
    {
        if (! service('monitorConfig')->demoMode) {
            return $this->fail('Demo mode is disabled. Set CONFIGMON_DEMO_MODE=true.', 403, 'demo_disabled');
        }

        $body  = $this->payload();
        $reset = $this->bool($body['reset'] ?? false);

        try {
            $result = service('demo')->seed($reset);
        } catch (Throwable $e) {
            service('jsonLogger')->error('Demo seed failed', ['component' => 'api', 'error' => $e]);

            return $this->fail('Unable to seed the demo environment: ' . $e->getMessage(), 500, 'demo_failed');
        }

        if (isset($result['target']['target']) && is_array($result['target']['target'])) {
            $result['target']['target'] = NotificationTargetModel::publicRow($result['target']['target']);
        }

        $this->audit('demo.seed', $result['directory'] ?? null, ['reset' => $reset, 'files' => $result['files'] ?? []]);

        return $this->ok($result);
    }

    public function change(): ResponseInterface
    {
        if (! service('monitorConfig')->demoMode) {
            return $this->fail('Demo mode is disabled. Set CONFIGMON_DEMO_MODE=true.', 403, 'demo_disabled');
        }

        $body = $this->payload();
        $file = isset($body['file']) ? (string) $body['file'] : null;
        $kind = isset($body['kind']) ? (string) $body['kind'] : 'update';

        try {
            $result = service('demo')->simulateChange($file, $kind);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'validation_error');
        } catch (Throwable $e) {
            service('jsonLogger')->error('Demo change failed', ['component' => 'api', 'error' => $e]);

            return $this->fail('Unable to simulate a change: ' . $e->getMessage(), 500, 'demo_failed');
        }

        $this->audit('demo.change', $result['file'] ?? $file, ['kind' => $result['kind'] ?? $kind, 'events' => $result['events'] ?? []]);

        return $this->ok($result);
    }
}

<?php

namespace App\Controllers\Api;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class Metrics extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $metrics = service('metrics');

        try {
            $overview = service('stats')->overview();

            $metrics->gauge('files_watched', (float) $overview['files']['active']);
            $metrics->gauge('files_error', (float) $overview['files']['error']);
            $metrics->gauge('queue_pending', (float) $overview['queue']['pending']);
            $metrics->gauge('queue_processing', (float) $overview['queue']['processing']);
            $metrics->gauge('queue_dead', (float) $overview['queue']['dead']);
            $metrics->gauge('dlq_open', (float) $overview['notifications']['dlq_open']);
            $metrics->gauge('circuit_open', (float) $overview['health']['circuit_open']);
        } catch (\Throwable) {
            // Serve whatever counters are available when the DB is degraded.
        }

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->setBody($metrics->renderPrometheus());
    }
}

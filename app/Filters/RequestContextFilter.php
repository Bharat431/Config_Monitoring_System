<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Adds a request id (reused as the trace id), response timing and basic
 * security headers, and emits one structured access log line per request.
 */
class RequestContextFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $tracer = service('tracer');
        $incoming = trim($request->getHeaderLine('X-Request-Id'));

        if (preg_match('/^[0-9a-f]{32}$/i', $incoming) === 1) {
            $tracer->adopt($incoming);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $tracer  = service('tracer');
        $traceId = $tracer->traceId();

        $response->setHeader('X-Request-Id', $traceId);
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('Referrer-Policy', 'no-referrer');

        $started = (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $duration = (int) round((microtime(true) - $started) * 1000);
        $response->setHeader('X-Response-Time', $duration . 'ms');

        service('jsonLogger')->info('HTTP request', [
            'component'   => 'http',
            'method'      => $request->getMethod(),
            'path'        => $request->getPath(),
            'status'      => $response->getStatusCode(),
            'duration_ms' => $duration,
            'ip'          => $request->getIPAddress(),
        ], $traceId);

        return null;
    }
}

<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Optional shared-secret protection for the management API.
 *
 * If CONFIGMON_API_KEY is empty the API is open (useful for local demos).
 * Clients may send the key as `X-API-Key` or `Authorization: Bearer <key>`.
 */
class ApiKeyFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $config = config('Monitor');
        $key    = (string) $config->apiKey;

        if ($key === '') {
            return null;
        }

        $provided = trim($request->getHeaderLine('X-API-Key'));
        if ($provided === '') {
            $auth = trim($request->getHeaderLine('Authorization'));
            if (preg_match('/^Bearer\s+(.+)$/i', $auth, $matches) === 1) {
                $provided = trim($matches[1]);
            }
        }

        if ($provided !== '' && hash_equals($key, $provided)) {
            return null;
        }

        return service('response')
            ->setStatusCode(401)
            ->setHeader('WWW-Authenticate', 'Bearer realm="config-monitor"')
            ->setJSON([
                'error' => [
                    'code'    => 'unauthorized',
                    'message' => 'A valid API key is required.',
                ],
            ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}

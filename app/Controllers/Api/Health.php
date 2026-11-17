<?php

namespace App\Controllers\Api;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class Health extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $health = service('stats')->health();
        $status = $health['status'] === 'error' ? 503 : 200;

        return $this->response->setStatusCode($status)->setJSON([
            'data' => $health,
            'meta' => ['request_id' => service('tracer')->traceId()],
        ]);
    }
}

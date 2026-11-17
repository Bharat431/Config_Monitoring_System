<?php

namespace App\Controllers\Api;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

class Stats extends BaseApiController
{
    public function index(): ResponseInterface
    {
        return $this->ok(service('stats')->overview());
    }

    public function series(): ResponseInterface
    {
        $hours = (int) ($this->request->getGet('hours') ?? 24);
        $hours = max(1, min(168, $hours));

        return $this->ok([
            'hours'  => $hours,
            'series' => service('stats')->hourlySeries($hours),
        ]);
    }
}

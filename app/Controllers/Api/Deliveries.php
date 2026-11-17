<?php

namespace App\Controllers\Api;

use App\Controllers\BaseApiController;
use App\Support\Time;
use CodeIgniter\HTTP\ResponseInterface;

class Deliveries extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = max(1, min(100, (int) ($this->request->getGet('per_page') ?? 25)));
        $status  = $this->request->getGet('status');
        $status  = is_string($status) && $status !== '' ? $status : null;

        $result = service('stats')->deliveriesPage($page, $perPage, $status);

        return $this->ok($result['rows'], array_merge(
            ['status' => $status],
            $this->pagination($page, $perPage, $result['total']),
        ));
    }

    public function deadLetters(): ResponseInterface
    {
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = max(1, min(100, (int) ($this->request->getGet('per_page') ?? 25)));

        $model  = model('DeadLetterModel');
        $result = $model->paginateUnresolved($page, $perPage);

        $rows = array_map(static function (array $row): array {
            $payload = json_decode((string) ($row['payload'] ?? ''), true);

            return [
                'id'          => (int) $row['id'],
                'queue_id'    => (int) $row['queue_id'],
                'event_id'    => $row['event_id'],
                'target_id'   => (int) $row['target_id'],
                'attempts'    => (int) $row['attempts'],
                'reason'      => $row['reason'],
                'error'       => $row['error'],
                'payload'     => is_array($payload) ? $payload : null,
                'resolved'    => (bool) $row['resolved'],
                'created_at'  => Time::isoFromDb((string) $row['created_at']),
            ];
        }, $result['rows']);

        return $this->ok($rows, $this->pagination($page, $perPage, $result['total']));
    }

    public function retry(string $id): ResponseInterface
    {
        $retried = service('queueWorker')->retryDeadLetter((int) $id);

        if (! $retried) {
            return $this->notFound('Dead letter not found or already resolved.');
        }

        $this->audit('dlq.retry', $id);

        return $this->ok(['dead_letter_id' => (int) $id, 'requeued' => true]);
    }
}

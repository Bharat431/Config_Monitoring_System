<?php

namespace App\Controllers\Api;

use App\Controllers\BaseApiController;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Events extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $page    = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage = max(1, min(100, (int) ($this->request->getGet('per_page') ?? 25)));

        $filters = [
            'file'     => $this->request->getGet('file'),
            'type'     => $this->request->getGet('type'),
            'since'    => $this->request->getGet('since'),
            'event_id' => $this->request->getGet('event_id'),
        ];

        $result = service('stats')->eventsPage($filters, $page, $perPage);

        return $this->ok($result['rows'], array_merge(
            ['filters' => array_filter($filters, static fn ($value): bool => $value !== null && $value !== '')],
            $this->pagination($page, $perPage, $result['total']),
        ));
    }

    public function show(string $eventId): ResponseInterface
    {
        $detail = service('stats')->eventDetail($eventId);
        if ($detail === null) {
            return $this->notFound('Change event not found.');
        }

        return $this->ok($detail);
    }

    public function replay(string $eventId): ResponseInterface
    {
        try {
            $result = service('queueWorker')->replayEvent($eventId);
        } catch (\InvalidArgumentException $e) {
            return $this->notFound($e->getMessage());
        } catch (Throwable $e) {
            service('jsonLogger')->error('Event replay failed', [
                'component' => 'api',
                'event_id'  => $eventId,
                'error'     => $e,
            ]);

            return $this->fail('Unable to replay the event.', 500, 'replay_failed');
        }

        $this->audit('event.replay', $eventId, ['requeued' => $result['requeued']]);

        return $this->ok($result);
    }
}

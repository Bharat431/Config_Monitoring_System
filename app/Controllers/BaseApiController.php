<?php

namespace App\Controllers;

use App\Support\Time;
use CodeIgniter\HTTP\ResponseInterface;

abstract class BaseApiController extends BaseController
{
    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        try {
            $json = $this->request->getJSON(true);
        } catch (\Throwable) {
            $json = null;
        }

        if (is_array($json)) {
            return $json;
        }

        $post = $this->request->getPost();
        if (is_array($post) && $post !== []) {
            return $post;
        }

        $raw = trim((string) $this->request->getBody());
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    protected function ok(mixed $data, array $meta = [], int $status = 200): ResponseInterface
    {
        return $this->respond($data, $meta, $status);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    protected function created(mixed $data, array $meta = []): ResponseInterface
    {
        return $this->respond($data, $meta, 201);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    protected function respond(mixed $data, array $meta = [], int $status = 200): ResponseInterface
    {
        $body = ['data' => $data];

        $meta = array_merge(['request_id' => service('tracer')->traceId(), 'time' => Time::isoNow()], $meta);
        $body['meta'] = $meta;

        return $this->response->setStatusCode($status)->setJSON($body);
    }

    protected function fail(string $message, int $status = 400, string $code = 'bad_request', mixed $details = null): ResponseInterface
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return $this->response->setStatusCode($status)->setJSON([
            'error' => $error,
            'meta'  => ['request_id' => service('tracer')->traceId(), 'time' => Time::isoNow()],
        ]);
    }

    protected function notFound(string $message = 'Resource not found.'): ResponseInterface
    {
        return $this->fail($message, 404, 'not_found');
    }

    /**
     * @return array<string, mixed>
     */
    protected function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'page'       => $page,
            'per_page'   => $perPage,
            'total'      => $total,
            'total_pages'=> $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    protected function actor(): string
    {
        $actor = trim($this->request->getHeaderLine('X-Actor'));

        return $actor === '' ? 'api' : mb_substr($actor, 0, 100);
    }

    protected function audit(string $action, ?string $subject = null, array $context = []): void
    {
        try {
            service('audit')->record($action, $subject, $context, $this->actor(), $this->request->getIPAddress());
        } catch (\Throwable) {
            // Auditing must not block the request.
        }
    }

    protected function bool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }
}

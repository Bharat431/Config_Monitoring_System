<?php

namespace App\Controllers\Api;

use App\Controllers\BaseApiController;
use App\Models\NotificationTargetModel;
use App\Support\Time;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Targets extends BaseApiController
{
    private const TYPES = ['webhook', 'slack', 'discord', 'teams'];

    public function index(): ResponseInterface
    {
        $rows = model('NotificationTargetModel')->orderBy('id', 'ASC')->findAll();

        $targets = array_map(function (array $row): array {
            return $this->present($row);
        }, $rows);

        return $this->ok($targets, ['count' => count($targets)]);
    }

    public function store(): ResponseInterface
    {
        $body    = $this->payload();
        $name    = trim((string) ($body['name'] ?? ''));
        $type    = strtolower(trim((string) ($body['type'] ?? 'webhook')));
        $url     = trim((string) ($body['url'] ?? ''));
        $enabled = $this->bool($body['enabled'] ?? true, true);

        $error = $this->validateTarget($name, $type, $url);
        if ($error !== null) {
            return $this->fail($error, 422, 'validation_error');
        }

        try {
            service('urlGuard')->assertSafe($url);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'invalid_url');
        }

        try {
            $headers = $this->headersJson($body['headers'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422, 'validation_error');
        }

        $id = model('NotificationTargetModel')->insert([
            'name'       => mb_substr($name, 0, 100),
            'type'       => $type,
            'url'        => $url,
            'secret'     => $this->nullableString($body['secret'] ?? null, 255),
            'token'      => $this->nullableString($body['token'] ?? null, 255),
            'headers'    => $headers,
            'enabled'    => $enabled ? 1 : 0,
            'created_at' => Time::dbNow(),
            'updated_at' => Time::dbNow(),
        ]);

        if ($id === false) {
            return $this->fail('Unable to persist the notification target.', 500, 'internal_error');
        }

        $target = model('NotificationTargetModel')->find((int) $id) ?? [];
        $this->audit('target.create', $target['name'] ?? (string) $id, ['type' => $type, 'url_host' => parse_url($url, PHP_URL_HOST)]);

        return $this->created(['target' => $this->present($target)]);
    }

    public function update(string $id): ResponseInterface
    {
        $model  = model('NotificationTargetModel');
        $target = $model->find((int) $id);
        if ($target === null) {
            return $this->notFound('Notification target not found.');
        }

        $body   = $this->payload();
        $update = [];

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '' || mb_strlen($name) > 100) {
                return $this->fail('Field "name" must be between 1 and 100 characters.', 422, 'validation_error');
            }
            $update['name'] = $name;
        }

        if (array_key_exists('type', $body)) {
            $type = strtolower(trim((string) $body['type']));
            if (! in_array($type, self::TYPES, true)) {
                return $this->fail('Field "type" must be one of: ' . implode(', ', self::TYPES) . '.', 422, 'validation_error');
            }
            $update['type'] = $type;
        }

        if (array_key_exists('url', $body)) {
            $url   = trim((string) $body['url']);
            $error = $this->validateTarget((string) ($update['name'] ?? $target['name']), (string) ($update['type'] ?? $target['type']), $url);
            if ($error !== null) {
                return $this->fail($error, 422, 'validation_error');
            }

            try {
                service('urlGuard')->assertSafe($url);
            } catch (\InvalidArgumentException $e) {
                return $this->fail($e->getMessage(), 422, 'invalid_url');
            }
            $update['url'] = $url;
        }

        if (array_key_exists('enabled', $body)) {
            $update['enabled'] = $this->bool($body['enabled']) ? 1 : 0;
        }

        if (array_key_exists('secret', $body)) {
            $update['secret'] = $this->nullableString($body['secret'], 255);
        }

        if (array_key_exists('token', $body)) {
            $update['token'] = $this->nullableString($body['token'], 255);
        }

        if (array_key_exists('headers', $body)) {
            try {
                $update['headers'] = $this->headersJson($body['headers']);
            } catch (\InvalidArgumentException $e) {
                return $this->fail($e->getMessage(), 422, 'validation_error');
            }
        }

        if ($update === []) {
            return $this->fail('No updatable fields were supplied.', 422, 'validation_error');
        }

        $update['updated_at'] = Time::dbNow();
        $model->update((int) $id, $update);

        $target = $model->find((int) $id) ?? $target;
        $this->audit('target.update', $target['name'] ?? (string) $id, ['fields' => array_keys($update)]);

        return $this->ok(['target' => $this->present($target)]);
    }

    public function delete(string $id): ResponseInterface
    {
        $model  = model('NotificationTargetModel');
        $target = $model->find((int) $id);
        if ($target === null) {
            return $this->notFound('Notification target not found.');
        }

        $model->delete((int) $id);
        model('CircuitBreakerModel')->where('target_id', (int) $id)->delete();

        $this->audit('target.delete', $target['name'] ?? (string) $id);

        return $this->ok(['removed' => true, 'id' => (int) $id]);
    }

    public function test(string $id): ResponseInterface
    {
        $model  = model('NotificationTargetModel');
        $target = $model->find((int) $id);
        if ($target === null) {
            return $this->notFound('Notification target not found.');
        }

        try {
            $response = service('notificationService')->test($target);
        } catch (Throwable $e) {
            service('jsonLogger')->error('Target test failed', [
                'component' => 'api',
                'target_id' => (int) $id,
                'error'     => $e,
            ]);

            return $this->fail('Target test failed: ' . $e->getMessage(), 502, 'delivery_failed');
        }

        $model->update((int) $id, [
            'last_test_at' => Time::dbNow(),
            'last_test_ok' => $response->isSuccess() ? 1 : 0,
            'updated_at'   => Time::dbNow(),
        ]);

        $this->audit('target.test', $target['name'] ?? (string) $id, ['status_code' => $response->statusCode]);

        return $this->ok([
            'target_id'   => (int) $id,
            'ok'          => $response->isSuccess(),
            'status_code' => $response->statusCode,
            'latency_ms'  => $response->latencyMs,
            'error'       => $response->isSuccess() ? null : $response->errorSummary(),
        ], [], $response->isSuccess() ? 200 : 502);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $public = NotificationTargetModel::publicRow($row);
        $breaker = model('CircuitBreakerModel')->forTarget((int) $row['id']);

        return [
            'id'           => (int) $public['id'],
            'name'         => $public['name'],
            'type'         => $public['type'],
            'url'          => $public['url'],
            'enabled'      => (bool) $public['enabled'],
            'has_secret'   => (bool) $public['has_secret'],
            'has_token'    => (bool) $public['has_token'],
            'headers'      => $this->decodeHeaders($public['headers'] ?? null),
            'last_test_at' => Time::isoFromDb($public['last_test_at'] ?? null),
            'last_test_ok' => $public['last_test_ok'] === null ? null : (bool) $public['last_test_ok'],
            'circuit'      => [
                'state'           => $breaker['state'] ?? 'closed',
                'failures'        => (int) ($breaker['failures'] ?? 0),
                'next_attempt_at' => Time::isoFromDb($breaker['next_attempt_at'] ?? null),
            ],
            'created_at'   => Time::isoFromDb($public['created_at'] ?? null),
            'updated_at'   => Time::isoFromDb($public['updated_at'] ?? null),
        ];
    }

    private function validateTarget(string $name, string $type, string $url): ?string
    {
        if (trim($name) === '' || mb_strlen($name) > 100) {
            return 'Field "name" must be between 1 and 100 characters.';
        }

        if (! in_array($type, self::TYPES, true)) {
            return 'Field "type" must be one of: ' . implode(', ', self::TYPES) . '.';
        }

        if ($url === '' || mb_strlen($url) > 2048) {
            return 'Field "url" is required (max 2048 characters).';
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'Field "url" must be a valid http(s) URL.';
        }

        return null;
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function headersJson(mixed $headers): ?string
    {
        if ($headers === null || $headers === '') {
            return null;
        }

        if (is_string($headers)) {
            $decoded = json_decode($headers, true);
            if (! is_array($decoded)) {
                throw new \InvalidArgumentException('Field "headers" must be a JSON object.');
            }
            $headers = $decoded;
        }

        if (! is_array($headers)) {
            return null;
        }

        $clean = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && (is_scalar($value) || $value === null)) {
                $clean[mb_substr($name, 0, 100)] = mb_substr((string) $value, 0, 500);
            }
        }

        return $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, string>
     */
    private function decodeHeaders(mixed $headers): array
    {
        if (! is_string($headers) || $headers === '') {
            return [];
        }

        $decoded = json_decode($headers, true);

        return is_array($decoded) ? $decoded : [];
    }
}

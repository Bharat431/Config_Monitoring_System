<?php

namespace App\Infrastructure\Notifications;

final class WebhookSigner
{
    public function sign(string $body, ?string $secret): ?string
    {
        if ($secret === null || $secret === '') {
            return null;
        }

        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    public function verify(string $body, ?string $secret, string $signature): bool
    {
        $expected = $this->sign($body, $secret);

        return $expected !== null && hash_equals($expected, $signature);
    }
}

<?php

namespace App\Infrastructure\Http;

use Config\Monitor;
use InvalidArgumentException;

/**
 * URL validation + SSRF protection for outbound webhooks.
 */
class UrlGuard
{
    public function __construct(private readonly Monitor $config)
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Malformed webhook URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Webhook URL must use http or https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Webhook URL must not contain credentials.');
        }

        $host = strtolower(trim($parts['host'], '[]'));

        $allowList = $this->config->webhookAllowedHosts();
        if ($allowList !== [] && ! in_array($host, $allowList, true)) {
            throw new InvalidArgumentException(sprintf('Webhook host "%s" is not in the allow-list.', $host));
        }

        if ($this->config->allowPrivateWebhooks) {
            return;
        }

        $ips = $this->resolve($host);
        if ($ips === []) {
            // Cannot resolve: delivery will fail naturally, do not block at save time.
            return;
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateOrReserved($ip)) {
                throw new InvalidArgumentException(sprintf(
                    'Webhook host "%s" resolves to a private/reserved address (%s). Set CONFIGMON_ALLOW_PRIVATE_WEBHOOKS=true to allow it.',
                    $host,
                    $ip,
                ));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = @gethostbynamel($host);

        return is_array($ips) ? $ips : [];
    }

    private function isPrivateOrReserved(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $ip === '::1'
                || str_starts_with(strtolower($ip), 'fc')
                || str_starts_with(strtolower($ip), 'fd')
                || str_starts_with(strtolower($ip), 'fe80');
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}

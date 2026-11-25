<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

use Paymos\ClientConfig;

final class Config
{
    public const DEFAULT_BASE_URL = 'https://api.paymos.io';
    public const ENV_LIVE = 'live';
    public const ENV_SANDBOX = 'sandbox';

    private string $environment;
    private string $projectId;
    private string $apiKey;
    private string $apiSecret;
    private string $webhookSecret;
    private string $baseUrl;
    private bool $debugLogging;

    private function __construct(
        string $environment,
        string $projectId,
        string $apiKey,
        string $apiSecret,
        string $webhookSecret,
        string $baseUrl,
        bool $debugLogging
    ) {
        $this->environment = $environment;
        $this->projectId = $projectId;
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->webhookSecret = $webhookSecret;
        $this->baseUrl = $baseUrl;
        $this->debugLogging = $debugLogging;
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $envRaw = self::stringValue($raw, 'environment');
        $environment = $envRaw === '' ? self::ENV_LIVE : strtolower($envRaw);
        if (!in_array($environment, [self::ENV_LIVE, self::ENV_SANDBOX], true)) {
            throw new \InvalidArgumentException('Paymos environment must be "live" or "sandbox".');
        }

        $projectId = self::stringValue($raw, 'project_id');
        if ($projectId === '') {
            throw new \InvalidArgumentException('Paymos project_id is required.');
        }

        $apiKey = self::stringValue($raw, 'api_key');
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Paymos api_key is required.');
        }
        if (str_starts_with(strtolower($apiKey), 'rk_')) {
            throw new \InvalidArgumentException(
                'Paymos payout API keys (rk_*) are not permitted; configure a Payment API key (pk_live_* or pk_test_*).'
            );
        }
        if ($environment === self::ENV_LIVE && !str_starts_with($apiKey, 'pk_live_')) {
            throw new \InvalidArgumentException('Paymos live environment requires a pk_live_* Payment API key.');
        }
        if ($environment === self::ENV_SANDBOX && !str_starts_with($apiKey, 'pk_test_')) {
            throw new \InvalidArgumentException('Paymos sandbox environment requires a pk_test_* Payment API key.');
        }

        $apiSecret = self::stringValue($raw, 'api_secret');
        if ($apiSecret === '') {
            throw new \InvalidArgumentException('Paymos api_secret is required.');
        }
        if ($environment === self::ENV_LIVE && !str_starts_with($apiSecret, 'sk_live_')) {
            throw new \InvalidArgumentException('Paymos live environment requires an sk_live_* API secret.');
        }
        if ($environment === self::ENV_SANDBOX && !str_starts_with($apiSecret, 'sk_test_')) {
            throw new \InvalidArgumentException('Paymos sandbox environment requires an sk_test_* API secret.');
        }

        $webhookSecret = self::stringValue($raw, 'webhook_secret');
        if ($webhookSecret === '') {
            throw new \InvalidArgumentException('Paymos webhook_secret is required.');
        }
        if (!str_starts_with($webhookSecret, 'whsec_') || strlen($webhookSecret) <= 6) {
            throw new \InvalidArgumentException('Paymos webhook_secret must start with whsec_ and include a signing token.');
        }

        $baseUrlRaw = self::stringValue($raw, 'base_url');
        $baseUrl = $baseUrlRaw === '' ? self::DEFAULT_BASE_URL : rtrim($baseUrlRaw, '/');
        self::assertHttpsBaseUrl($baseUrl);

        $debugLogging = self::boolValue($raw, 'debug_logging');

        return new self(
            $environment,
            $projectId,
            $apiKey,
            $apiSecret,
            $webhookSecret,
            $baseUrl,
            $debugLogging
        );
    }

    public function toClientConfig(int $timeoutSeconds = 30): ClientConfig
    {
        return new ClientConfig(
            $this->apiKey,
            $this->apiSecret,
            $this->baseUrl,
            $timeoutSeconds
        );
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function isSandbox(): bool
    {
        return $this->environment === self::ENV_SANDBOX;
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function apiSecret(): string
    {
        return $this->apiSecret;
    }

    public function webhookSecret(): string
    {
        return $this->webhookSecret;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function debugLogging(): bool
    {
        return $this->debugLogging;
    }

    public function sanitizeMessage(string $message): string
    {
        return self::redactSecrets($message, [$this->apiSecret, $this->webhookSecret]);
    }

    /**
     * @param list<string> $secrets
     */
    public static function redactSecrets(string $message, array $secrets = []): string
    {
        $sanitized = $message;
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $sanitized = str_replace($secret, '[REDACTED]', $sanitized);
            }
        }

        $replacedAuth = preg_replace('/\bHMAC-SHA256\s+[^\s,;]+/i', 'HMAC-SHA256 [REDACTED]', $sanitized);
        if (is_string($replacedAuth)) {
            $sanitized = $replacedAuth;
        }

        $replacedKeys = preg_replace('/\b(?:(?:pk|sk|rk)_(?:live|test)|whsec)_[A-Za-z0-9_-]+\b/', '[REDACTED]', $sanitized);

        return is_string($replacedKeys) ? $replacedKeys : $sanitized;
    }

    /**
     * Prevent accidental secret exposure via var_dump() or debug traces.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'environment' => $this->environment,
            'projectId' => $this->projectId,
            'apiKey' => $this->apiKey,
            'apiSecret' => '[REDACTED]',
            'webhookSecret' => '[REDACTED]',
            'baseUrl' => $this->baseUrl,
            'debugLogging' => $this->debugLogging,
        ];
    }

    private static function assertHttpsBaseUrl(string $baseUrl): void
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Paymos base_url must be a valid HTTPS URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (
            $scheme !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
        ) {
            throw new \InvalidArgumentException('Paymos base_url must be a valid HTTPS URL.');
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function stringValue(array $raw, string $key): string
    {
        if (!array_key_exists($key, $raw) || !is_scalar($raw[$key])) {
            return '';
        }

        return trim((string) $raw[$key]);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function boolValue(array $raw, string $key): bool
    {
        if (!array_key_exists($key, $raw)) {
            return false;
        }

        $value = $raw[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}

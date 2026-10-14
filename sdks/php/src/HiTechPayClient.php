<?php

declare(strict_types=1);

namespace HiTechPay;

use HiTechPay\Exception\ConnectionException;
use HiTechPay\Exception\HiTechPayException;
use HiTechPay\Http\CurlTransport;
use HiTechPay\Http\Transport;
use HiTechPay\Resources\Customers;
use HiTechPay\Resources\Disputes;
use HiTechPay\Resources\Mandates;
use HiTechPay\Resources\PaymentLinks;
use HiTechPay\Resources\PaymentMethods;
use HiTechPay\Resources\Payments;
use HiTechPay\Resources\Refunds;

/**
 * HiTechPay API client (Hyperswitch v1 compatible).
 *
 * $client = new HiTechPayClient(['api_key' => getenv('HITECHPAY_SECRET_KEY')]);
 * $payment = $client->payments->create([...]);
 */
final class HiTechPayClient
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.hitechpay.vn';
    public const SANDBOX_BASE_URL = 'https://sandbox-api.hitechpay.vn';

    public readonly Payments $payments;
    public readonly Refunds $refunds;
    public readonly Customers $customers;
    public readonly PaymentMethods $paymentMethods;
    public readonly PaymentLinks $paymentLinks;
    public readonly Mandates $mandates;
    public readonly Disputes $disputes;

    private string $apiKey;
    private string $baseUrl;
    private int $timeoutMs;
    private int $maxRetries;
    private int $retryBaseDelayMs;
    private int $retryMaxDelayMs;
    private Transport $transport;
    /** @var array<string,string> */
    private array $defaultHeaders;

    /**
     * @param array{
     *   api_key: string,
     *   base_url?: string,
     *   timeout_ms?: int,
     *   max_retries?: int,
     *   retry_base_delay_ms?: int,
     *   retry_max_delay_ms?: int,
     *   transport?: Transport,
     *   headers?: array<string,string>
     * }|string $config Config array, or just the secret API key.
     */
    public function __construct(array|string $config)
    {
        if (is_string($config)) {
            $config = ['api_key' => $config];
        }
        $apiKey = $config['api_key'] ?? '';
        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new \InvalidArgumentException('HiTechPay: api_key is required');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($config['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeoutMs = (int) ($config['timeout_ms'] ?? 30_000);
        $this->maxRetries = max(0, (int) ($config['max_retries'] ?? 2));
        $this->retryBaseDelayMs = (int) ($config['retry_base_delay_ms'] ?? 500);
        $this->retryMaxDelayMs = (int) ($config['retry_max_delay_ms'] ?? 8_000);
        $this->transport = $config['transport'] ?? new CurlTransport();
        $this->defaultHeaders = $config['headers'] ?? [];

        $this->payments = new Payments($this);
        $this->refunds = new Refunds($this);
        $this->customers = new Customers($this);
        $this->paymentMethods = new PaymentMethods($this);
        $this->paymentLinks = new PaymentLinks($this);
        $this->mandates = new Mandates($this);
        $this->disputes = new Disputes($this);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Low-level request. Retries 429/5xx/network errors for idempotent requests
     * (GET/DELETE by default, or when $options['idempotent'] is true).
     *
     * @param array<string,mixed>|null $body
     * @param array<string,scalar|null>|null $query
     * @param array{headers?:array<string,string>, timeout_ms?:int, max_retries?:int, idempotent?:bool} $options
     * @return array<mixed>
     * @throws HiTechPayException
     */
    public function request(string $method, string $path, ?array $body = null, ?array $query = null, array $options = []): array
    {
        $method = strtoupper($method);
        $url = $this->baseUrl . '/' . ltrim($path, '/') . self::buildQuery($query);

        $headers = array_merge(
            [
                'Accept' => 'application/json',
                'User-Agent' => 'hitechpay-php/' . self::VERSION . ' php/' . PHP_VERSION,
            ],
            $this->defaultHeaders,
            $options['headers'] ?? [],
            ['api-key' => $this->apiKey],
        );

        $payload = null;
        if ($body !== null && $method !== 'GET') {
            // Empty array must encode as {} not [].
            $payload = json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        }

        $idempotent = $options['idempotent'] ?? in_array($method, ['GET', 'DELETE'], true);
        $maxRetries = $idempotent ? max(0, (int) ($options['max_retries'] ?? $this->maxRetries)) : 0;
        $timeoutMs = (int) ($options['timeout_ms'] ?? $this->timeoutMs);

        for ($attempt = 0; ; $attempt++) {
            try {
                $res = $this->transport->send($method, $url, $headers, $payload, $timeoutMs);
            } catch (ConnectionException $e) {
                if ($attempt < $maxRetries) {
                    $this->sleepMs($this->backoffMs($attempt, null));
                    continue;
                }
                throw $e;
            }

            $status = $res['status'];
            $resHeaders = array_change_key_case($res['headers'], CASE_LOWER);
            $decoded = null;
            if ($res['body'] !== '') {
                try {
                    $decoded = json_decode($res['body'], true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $decoded = $res['body'];
                }
            }

            if ($status >= 200 && $status < 300) {
                if (is_array($decoded)) {
                    return $decoded;
                }
                return $decoded === null ? [] : ['data' => $decoded];
            }

            if (($status === 429 || $status >= 500) && $attempt < $maxRetries) {
                $this->sleepMs($this->backoffMs($attempt, $resHeaders['retry-after'] ?? null));
                continue;
            }

            throw HiTechPayException::fromResponse($status, $decoded, $resHeaders);
        }
    }

    /** Encode a user-supplied id as a URL path segment. */
    public static function seg(string $id): string
    {
        if ($id === '') {
            throw new \InvalidArgumentException('HiTechPay: id must be a non-empty string');
        }
        return rawurlencode($id);
    }

    /** @param array<string,scalar|null>|null $query */
    public static function buildQuery(?array $query): string
    {
        if (!$query) {
            return '';
        }
        $parts = [];
        foreach ($query as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return $parts ? '?' . implode('&', $parts) : '';
    }

    private function backoffMs(int $attempt, ?string $retryAfter): int
    {
        if ($retryAfter !== null && is_numeric($retryAfter) && (float) $retryAfter >= 0) {
            return (int) min((float) $retryAfter * 1000, $this->retryMaxDelayMs);
        }
        $exp = min($this->retryBaseDelayMs * (2 ** $attempt), $this->retryMaxDelayMs);
        return (int) ($exp / 2 + mt_rand(0, (int) max(0, $exp / 2)));
    }

    private function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}

<?php

declare(strict_types=1);

namespace HiTechPay\Resources;

/**
 * Options for every method: ['headers' => [...], 'timeout_ms' => int, 'max_retries' => int, 'idempotent' => bool]
 */
final class Payments extends AbstractResource
{
    /** POST /payments */
    public function create(array $params, array $options = []): array
    {
        return $this->client->request('POST', '/payments', $params, null, $options);
    }

    /** GET /payments/{payment_id} — $query e.g. ['force_sync' => true] */
    public function retrieve(string $paymentId, array $query = [], array $options = []): array
    {
        return $this->client->request('GET', '/payments/' . self::seg($paymentId), null, $query, $options);
    }

    /** POST /payments/{payment_id} */
    public function update(string $paymentId, array $params, array $options = []): array
    {
        return $this->client->request('POST', '/payments/' . self::seg($paymentId), $params, null, $options);
    }

    /** POST /payments/{payment_id}/confirm */
    public function confirm(string $paymentId, array $params = [], array $options = []): array
    {
        return $this->client->request('POST', '/payments/' . self::seg($paymentId) . '/confirm', $params, null, $options);
    }

    /** POST /payments/{payment_id}/capture */
    public function capture(string $paymentId, array $params = [], array $options = []): array
    {
        return $this->client->request('POST', '/payments/' . self::seg($paymentId) . '/capture', $params, null, $options);
    }

    /** POST /payments/{payment_id}/cancel */
    public function cancel(string $paymentId, array $params = [], array $options = []): array
    {
        return $this->client->request('POST', '/payments/' . self::seg($paymentId) . '/cancel', $params, null, $options);
    }

    /** GET /payments/list */
    public function list(array $query = [], array $options = []): array
    {
        return $this->client->request('GET', '/payments/list', null, $query, $options);
    }
}

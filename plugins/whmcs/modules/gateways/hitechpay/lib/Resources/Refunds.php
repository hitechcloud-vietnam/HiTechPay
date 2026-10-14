<?php

declare(strict_types=1);

namespace HiTechPay\Resources;

final class Refunds extends AbstractResource
{
    /** POST /refunds */
    public function create(array $params, array $options = []): array
    {
        return $this->client->request('POST', '/refunds', $params, null, $options);
    }

    /** GET /refunds/{refund_id} */
    public function retrieve(string $refundId, array $options = []): array
    {
        return $this->client->request('GET', '/refunds/' . self::seg($refundId), null, null, $options);
    }

    /** POST /refunds/{refund_id} */
    public function update(string $refundId, array $params, array $options = []): array
    {
        return $this->client->request('POST', '/refunds/' . self::seg($refundId), $params, null, $options);
    }

    /** POST /refunds/list (read-only filter, retried like a GET) */
    public function list(array $params = [], array $options = []): array
    {
        return $this->client->request('POST', '/refunds/list', $params, null, $options + ['idempotent' => true]);
    }
}

<?php

declare(strict_types=1);

namespace HiTechPay\Resources;

final class Customers extends AbstractResource
{
    /** POST /customers */
    public function create(array $params, array $options = []): array
    {
        return $this->client->request('POST', '/customers', $params, null, $options);
    }

    /** GET /customers/{customer_id} */
    public function retrieve(string $customerId, array $options = []): array
    {
        return $this->client->request('GET', '/customers/' . self::seg($customerId), null, null, $options);
    }

    /** POST /customers/{customer_id} */
    public function update(string $customerId, array $params, array $options = []): array
    {
        return $this->client->request('POST', '/customers/' . self::seg($customerId), $params, null, $options);
    }

    /** DELETE /customers/{customer_id} */
    public function delete(string $customerId, array $options = []): array
    {
        return $this->client->request('DELETE', '/customers/' . self::seg($customerId), null, null, $options);
    }

    /** GET /customers/list */
    public function list(array $query = [], array $options = []): array
    {
        return $this->client->request('GET', '/customers/list', null, $query, $options);
    }
}

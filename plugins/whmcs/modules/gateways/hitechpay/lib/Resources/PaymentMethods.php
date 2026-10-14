<?php

declare(strict_types=1);

namespace HiTechPay\Resources;

final class PaymentMethods extends AbstractResource
{
    /** GET /customers/{customer_id}/payment_methods */
    public function list(string $customerId, array $query = [], array $options = []): array
    {
        return $this->client->request('GET', '/customers/' . self::seg($customerId) . '/payment_methods', null, $query, $options);
    }
}

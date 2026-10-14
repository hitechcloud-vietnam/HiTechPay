<?php

declare(strict_types=1);

namespace HiTechPay\Resources;

final class Disputes extends AbstractResource
{
    /** GET /disputes/{dispute_id} */
    public function retrieve(string $disputeId, array $options = []): array
    {
        return $this->client->request('GET', '/disputes/' . self::seg($disputeId), null, null, $options);
    }

    /** GET /disputes/list */
    public function list(array $query = [], array $options = []): array
    {
        return $this->client->request('GET', '/disputes/list', null, $query, $options);
    }
}

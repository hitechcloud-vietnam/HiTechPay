<?php

declare(strict_types=1);

namespace HiTechPay\Resources;

final class Mandates extends AbstractResource
{
    /** GET /mandates/{mandate_id} */
    public function retrieve(string $mandateId, array $options = []): array
    {
        return $this->client->request('GET', '/mandates/' . self::seg($mandateId), null, null, $options);
    }

    /** POST /mandates/revoke/{mandate_id} */
    public function revoke(string $mandateId, array $options = []): array
    {
        return $this->client->request('POST', '/mandates/revoke/' . self::seg($mandateId), null, null, $options);
    }
}

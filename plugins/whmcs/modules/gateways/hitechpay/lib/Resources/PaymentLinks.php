<?php

declare(strict_types=1);

namespace HiTechPay\Resources;

final class PaymentLinks extends AbstractResource
{
    /** GET /payment_link/{payment_link_id} */
    public function retrieve(string $paymentLinkId, array $options = []): array
    {
        return $this->client->request('GET', '/payment_link/' . self::seg($paymentLinkId), null, null, $options);
    }
}

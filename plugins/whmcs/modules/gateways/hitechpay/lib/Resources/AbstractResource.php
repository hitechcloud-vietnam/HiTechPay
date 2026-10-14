<?php

declare(strict_types=1);

namespace HiTechPay\Resources;

use HiTechPay\HiTechPayClient;

abstract class AbstractResource
{
    public function __construct(protected readonly HiTechPayClient $client)
    {
    }

    protected static function seg(string $id): string
    {
        return HiTechPayClient::seg($id);
    }
}

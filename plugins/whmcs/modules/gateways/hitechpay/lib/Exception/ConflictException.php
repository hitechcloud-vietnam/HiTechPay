<?php

declare(strict_types=1);

namespace HiTechPay\Exception;

/** 409 — conflict, e.g. duplicate payment_id. */
class ConflictException extends HiTechPayException
{
}

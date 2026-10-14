<?php

declare(strict_types=1);

namespace HiTechPay\Http;

use HiTechPay\Exception\ConnectionException;

/**
 * Minimal HTTP transport abstraction (lets you plug in a custom client or a mock in tests).
 */
interface Transport
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, headers:array<string,string>, body:string} headers lower-cased
     * @throws ConnectionException on network failure / timeout
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array;
}

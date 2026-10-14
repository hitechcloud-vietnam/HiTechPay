<?php

declare(strict_types=1);

namespace HiTechPay\Http;

use HiTechPay\Exception\ConnectionException;

final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new ConnectionException('Unable to initialise cURL');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 10_000),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($ch);
        if ($result === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            $timedOut = $errno === CURLE_OPERATION_TIMEDOUT;
            throw new ConnectionException(
                $timedOut
                    ? "HiTechPay request timed out after {$timeoutMs}ms"
                    : "HiTechPay network error: {$error} (cURL {$errno})",
                0,
                $timedOut ? 'timeout' : 'network',
                $timedOut ? 'timeout' : 'network',
            );
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $result];
    }
}

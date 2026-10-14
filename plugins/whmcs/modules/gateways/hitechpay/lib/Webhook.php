<?php

declare(strict_types=1);

namespace HiTechPay;

use HiTechPay\Exception\SignatureVerificationException;

/**
 * Outgoing webhook verification.
 *
 * HiTechPay signs the raw JSON body with HMAC-SHA512 (hex) using the profile's
 * "Payment Response Hash Key" and sends it in `x-webhook-signature-512`.
 * `x-webhook-signature-256` (HMAC-SHA256 hex) is also supported.
 */
final class Webhook
{
    public const SIGNATURE_HEADER_512 = 'x-webhook-signature-512';
    public const SIGNATURE_HEADER_256 = 'x-webhook-signature-256';

    /**
     * @param string $rawBody exact raw request body, e.g. file_get_contents('php://input')
     * @param string|array<string,string|string[]>|null $signatureHeader the header value, or the headers array
     * @param string $paymentResponseHashKey
     */
    public static function verify(string $rawBody, string|array|null $signatureHeader, string $paymentResponseHashKey): bool
    {
        if ($paymentResponseHashKey === '') {
            throw new \InvalidArgumentException('HiTechPay webhook: paymentResponseHashKey is required');
        }
        $resolved = self::resolveSignature($signatureHeader);
        if ($resolved === null) {
            return false;
        }
        [$signature, $algorithms] = $resolved;
        $signature = strtolower($signature);
        $ok = false;
        foreach ($algorithms as $alg) {
            $expected = hash_hmac($alg, $rawBody, $paymentResponseHashKey);
            // hash_equals is constant-time for equal-length strings.
            $ok = hash_equals($expected, $signature) || $ok;
        }
        return $ok;
    }

    /**
     * Verify and decode the webhook event.
     *
     * @return array<string,mixed>
     * @throws SignatureVerificationException
     */
    public static function constructEvent(string $rawBody, string|array|null $signatureHeader, string $paymentResponseHashKey): array
    {
        if (self::resolveSignature($signatureHeader) === null) {
            throw new SignatureVerificationException(
                'Missing webhook signature (expected ' . self::SIGNATURE_HEADER_512 . ' or ' . self::SIGNATURE_HEADER_256 . ')',
                400,
                'signature_missing',
            );
        }
        if (!self::verify($rawBody, $signatureHeader, $paymentResponseHashKey)) {
            throw new SignatureVerificationException('Webhook signature verification failed', 400, 'signature_invalid');
        }
        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SignatureVerificationException('Webhook body is not valid JSON', 400, 'invalid_payload', null, null, [], $e);
        }
        if (!is_array($event)) {
            throw new SignatureVerificationException('Webhook body is not a JSON object', 400, 'invalid_payload');
        }
        return $event;
    }

    /** Compute the hex HMAC signature for a body (useful for tests). */
    public static function computeSignature(string $rawBody, string $key, string $algorithm = 'sha512'): string
    {
        return hash_hmac($algorithm, $rawBody, $key);
    }

    /**
     * Read the signature from the current PHP request ($_SERVER) when no framework is used.
     */
    public static function signatureFromGlobals(): ?string
    {
        return $_SERVER['HTTP_X_WEBHOOK_SIGNATURE_512'] ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE_256'] ?? null;
    }

    /**
     * @param string|array<string,mixed>|null $input
     * @return array{0:string,1:list<string>}|null
     */
    private static function resolveSignature(string|array|null $input): ?array
    {
        if ($input === null) {
            return null;
        }
        if (is_string($input)) {
            $sig = trim($input);
            if ($sig === '') {
                return null;
            }
            return match (strlen($sig)) {
                128 => [$sig, ['sha512']],
                64 => [$sig, ['sha256']],
                default => [$sig, ['sha512', 'sha256']],
            };
        }
        $lower = [];
        foreach ($input as $k => $v) {
            $key = strtolower(str_replace('_', '-', (string) $k));
            if (str_starts_with($key, 'http-')) {
                $key = substr($key, 5); // $_SERVER style HTTP_X_WEBHOOK_SIGNATURE_512
            }
            $lower[$key] = is_array($v) ? (string) ($v[0] ?? '') : (string) $v;
        }
        $s512 = trim($lower[self::SIGNATURE_HEADER_512] ?? '');
        if ($s512 !== '') {
            return [$s512, ['sha512']];
        }
        $s256 = trim($lower[self::SIGNATURE_HEADER_256] ?? '');
        if ($s256 !== '') {
            return [$s256, ['sha256']];
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace HiTechPay\Exception;

/**
 * Base exception for all HiTechPay API errors.
 *
 * Hyperswitch-compatible error bodies look like:
 *   {"error":{"type":"invalid_request","message":"...","code":"IR_06"}}
 */
class HiTechPayException extends \RuntimeException
{
    /**
     * @param array<string,string> $headers lower-cased response headers
     */
    public function __construct(
        string $message,
        private readonly int $status = 0,
        private readonly ?string $errorCode = null,
        private readonly ?string $errorType = null,
        private readonly mixed $raw = null,
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** HTTP status code (0 when no response was received). */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** API error code, e.g. "IR_06" or "HE_02". */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** API error type, e.g. "invalid_request". */
    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    /** Raw response body (decoded array when JSON, otherwise string). */
    public function getRaw(): mixed
    {
        return $this->raw;
    }

    /** @return array<string,string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getRequestId(): ?string
    {
        return $this->headers['x-request-id'] ?? null;
    }

    /**
     * Build the most specific exception for an HTTP error response.
     *
     * @param array<string,string> $headers
     */
    public static function fromResponse(int $status, mixed $body, array $headers = []): self
    {
        $message = null;
        $code = null;
        $type = null;
        if (is_array($body)) {
            $err = $body['error'] ?? null;
            if (is_array($err)) {
                $message = $err['message'] ?? $err['reason'] ?? null;
                $code = $err['code'] ?? null;
                $type = $err['type'] ?? null;
            } elseif (is_string($err)) {
                $message = $err;
            }
            $message ??= $body['message'] ?? $body['error_message'] ?? null;
            $code ??= $body['code'] ?? $body['error_code'] ?? null;
            $type ??= $body['type'] ?? null;
        } elseif (is_string($body) && trim($body) !== '') {
            $message = substr(trim($body), 0, 500);
        }
        $message = is_string($message) ? $message : "HiTechPay API request failed with HTTP {$status}";
        $code = is_scalar($code) ? (string) $code : null;
        $type = is_scalar($type) ? (string) $type : null;

        $class = match (true) {
            $status === 400, $status === 422 => InvalidRequestException::class,
            $status === 401, $status === 403 => AuthenticationException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ApiErrorException::class,
            default => self::class,
        };

        return new $class($message, $status, $code, $type, $body, $headers);
    }
}

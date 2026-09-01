<?php

namespace App\Mail\Exceptions;

/**
 * The provider is telling us to slow down — a 429, or Gmail's 403-with-
 * rateLimitExceeded variant. Unlike an auth failure it is guaranteed to pass,
 * so jobs respond by rescheduling themselves rather than failing or hammering.
 */
class RateLimitedException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

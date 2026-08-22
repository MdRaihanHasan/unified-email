<?php

namespace App\Mail\Data;

use DateTimeImmutable;

final readonly class SendResult
{
    public function __construct(
        public string $providerMessageId,
        public ?string $providerThreadId = null,
        public ?string $rfc822MessageId = null,
        public ?DateTimeImmutable $sentAt = null,
    ) {}
}

<?php

namespace App\Mail\Data;

final readonly class MessageBody
{
    /** @param  list<RemoteAttachment>  $attachments */
    public function __construct(
        public ?string $html = null,
        public ?string $text = null,
        public array $attachments = [],
    ) {}
}

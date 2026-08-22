<?php

namespace App\Mail\Data;

final readonly class RemoteAttachment
{
    public function __construct(
        public string $filename,
        public ?string $remoteId = null,
        public ?string $mimeType = null,
        public ?int $sizeBytes = null,
        public bool $isInline = false,
        public ?string $contentId = null,
    ) {}
}

<?php

namespace App\Mail\Data;

/**
 * A flag or folder change on a message we already have. Null means "unchanged" —
 * providers report partial updates and we must not clobber what they omit.
 */
final readonly class MessageUpdate
{
    /** @param  list<string>|null  $folderRemoteIds */
    public function __construct(
        public string $providerMessageId,
        public ?bool $isRead = null,
        public ?bool $isStarred = null,
        public ?array $folderRemoteIds = null,
    ) {}
}

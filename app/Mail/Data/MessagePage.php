<?php

namespace App\Mail\Data;

/** One page of a backfill walk. */
final readonly class MessagePage
{
    /** @param  list<RemoteMessage>  $messages */
    public function __construct(
        public array $messages,
        public ?string $nextCursor = null,
    ) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}

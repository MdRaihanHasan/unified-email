<?php

namespace App\Mail\Data;

/** The result of one incremental sync pass. */
final readonly class ChangeSet
{
    /**
     * @param  list<RemoteMessage>  $created
     * @param  list<MessageUpdate>  $updated
     * @param  list<string>  $deletedIds  provider message ids
     */
    /**
     * $hasMore: the provider stopped early (a bulk change produced more history
     * than one pass should hold in memory); the cursor covers what IS here, so
     * the caller applies this batch, commits the cursor, and asks again.
     */
    public function __construct(
        public array $created = [],
        public array $updated = [],
        public array $deletedIds = [],
        public ?SyncCursor $cursor = null,
        public bool $hasMore = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->created === [] && $this->updated === [] && $this->deletedIds === [];
    }
}

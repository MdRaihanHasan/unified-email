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
    public function __construct(
        public array $created = [],
        public array $updated = [],
        public array $deletedIds = [],
        public ?SyncCursor $cursor = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->created === [] && $this->updated === [] && $this->deletedIds === [];
    }
}

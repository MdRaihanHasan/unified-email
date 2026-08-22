<?php

namespace App\Mail\Data;

/**
 * Opaque, provider-shaped incremental sync position.
 *
 *   gmail_api  {"historyId": "123456"}
 *   imap       {"INBOX": {"uidvalidity": 12, "uidnext": 4801}}
 *   graph      {"deltaLink": "https://graph.microsoft.com/..."}
 *
 * Every one of these can go stale — Gmail expires old historyIds, Graph returns
 * syncStateNotFound, IMAP bumps UIDVALIDITY. Adapters signal that by throwing
 * CursorInvalidException, which is the only correct trigger for a full resync.
 */
final readonly class SyncCursor
{
    public function __construct(public array $value = []) {}

    public function isEmpty(): bool
    {
        return $this->value === [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->value[$key] ?? $default;
    }

    public function with(string $key, mixed $value): self
    {
        return new self([...$this->value, $key => $value]);
    }

    public function toArray(): array
    {
        return $this->value;
    }
}

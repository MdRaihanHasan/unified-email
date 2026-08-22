<?php

namespace App\Mail\Data;

/** A local flag change to push up to the provider. Null means "leave alone". */
final readonly class FlagChange
{
    public function __construct(
        public ?bool $isRead = null,
        public ?bool $isStarred = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->isRead === null && $this->isStarred === null;
    }
}

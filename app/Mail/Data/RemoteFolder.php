<?php

namespace App\Mail\Data;

use App\Enums\FolderRole;

final readonly class RemoteFolder
{
    public function __construct(
        public string $remoteId,
        public string $name,
        public FolderRole $role = FolderRole::Custom,
        public ?string $path = null,
        public bool $isLabel = false,
        public bool $isSelectable = true,
        public int $totalCount = 0,
        public int $unreadCount = 0,
    ) {}
}

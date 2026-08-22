<?php

namespace App\Jobs;

use App\Models\MailAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Walks an account's history one page at a time, newest first.
 *
 * Chunked rather than looped to completion so a large mailbox cannot monopolise a
 * worker, and so the inbox is usable while the tail is still arriving. The cursor
 * for the *next* page is recorded before this job ends, which is what makes it
 * resumable after a crash or a redeploy.
 */
class BackfillJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly MailAccount $account,
        public readonly ?int $folderId = null,
        public readonly ?string $cursor = null,
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('backfill:'.$this->account->id))->dontRelease()->expireAfter(1800)];
    }

    public function handle(): void
    {
        // TODO Phase 1:
        //  1. listFolders() and upsert them, skipping Folder::shouldBackfill() === false
        //     (Gmail's All Mail mirrors every other folder).
        //  2. Take the sync cursor BEFORE the first page, so changes made during a
        //     long backfill are not missed once incremental sync takes over.
        //  3. fetchPage() -> persist -> re-dispatch self with the next cursor.
        //  4. When the last folder runs out of pages, set backfill_done_at.
        $this->pendingPhase();
    }

    private function pendingPhase(): never
    {
        throw new \LogicException('BackfillJob is not implemented yet (roadmap Phase 1).');
    }
}

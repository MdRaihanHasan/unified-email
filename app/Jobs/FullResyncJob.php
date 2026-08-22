<?php

namespace App\Jobs;

use App\Models\MailAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recovery path for an expired incremental cursor.
 *
 * Clears the cursor and the backfill marker so BackfillJob walks the mailbox again.
 * Stored messages are deliberately NOT deleted first: the unique constraint on
 * (mail_account_id, provider_message_id) makes the re-walk an upsert, so a resync
 * repairs the account without the inbox ever going empty.
 */
class FullResyncJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly MailAccount $account) {}

    public function handle(): void
    {
        $this->account->update([
            'sync_cursor' => null,
            'backfill_done_at' => null,
        ]);

        // Folder-level progress has to go too, or the walk resumes from wherever the
        // last one stopped and never revisits what it already passed.
        $this->account->folders()->update([
            'backfill_cursor' => null,
            'backfill_done_at' => null,
        ]);

        BackfillJob::dispatch($this->account);
    }
}

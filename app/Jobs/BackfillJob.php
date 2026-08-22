<?php

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Mail\Contracts\MailboxProvider;
use App\Mail\Data\RemoteMessage;
use App\Mail\Exceptions\AuthenticationFailedException;
use App\Mail\Support\MessageWriter;
use App\Models\Folder;
use App\Models\MailAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Walks an account's history one page at a time, newest first.
 *
 * Re-dispatches itself instead of looping to completion, so a large mailbox cannot
 * monopolise a worker and the inbox is usable while the tail is still arriving.
 * Progress is stored per folder in the database, which is what makes an interrupted
 * backfill resume rather than restart.
 */
class BackfillJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300];

    public function __construct(public readonly MailAccount $account) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('backfill:'.$this->account->id))->dontRelease()->expireAfter(1800)];
    }

    public function handle(MessageWriter $writer): void
    {
        $account = $this->account->fresh();

        if ($account === null || ! $account->status->shouldSync() || $account->hasFinishedBackfill()) {
            return;
        }

        $driver = $account->driver();

        try {
            $this->walk($account, $driver, $writer);
        } catch (AuthenticationFailedException $e) {
            // Same rule as incremental sync: a revoked token or rotated app password
            // cannot be retried into working, and a backfill that dies silently
            // leaves an account that never finishes and never says why.
            $account->update(['status' => AccountStatus::AuthError, 'last_error' => $e->getMessage()]);

            $this->fail($e);
        }
    }

    private function walk(MailAccount $account, MailboxProvider $driver, MessageWriter $writer): void
    {
        if ($account->folders()->doesntExist()) {
            $writer->storeFolders($account, $driver->listFolders($account));

            // Take the delta cursor BEFORE reading any message. Anything that changes
            // during a long backfill then still shows up once incremental sync takes
            // over; taking it afterwards would silently lose that window.
            if (($account->sync_cursor ?? []) === []) {
                $account->update(['sync_cursor' => $driver->currentCursor($account)->toArray()]);
            }
        }

        $folder = $account->folders()
            ->whereNull('backfill_done_at')
            ->orderByRaw("case when role = 'inbox' then 0 else 1 end") // inbox first: it is what the user opens
            ->orderBy('id')
            ->get()
            ->first(fn (Folder $folder) => $folder->shouldBackfill());

        if ($folder === null) {
            $account->update(['backfill_done_at' => now(), 'last_synced_at' => now()]);

            Log::info('Backfill complete', ['account' => $account->email]);

            return;
        }

        $page = $driver->fetchPage($account, $folder, $folder->backfill_cursor);

        foreach ($page->messages as $remote) {
            $writer->store($account, $remote, recount: false);
        }

        foreach ($this->threadIds($page->messages, $account) as $threadId) {
            $writer->recountThread($threadId);
        }

        if ($page->hasMore()) {
            $folder->update(['backfill_cursor' => $page->nextCursor]);
        } else {
            $folder->update(['backfill_cursor' => null, 'backfill_done_at' => now()]);
        }

        // Next page (or next folder) as a fresh job, so each unit of work is small
        // and independently retryable.
        self::dispatch($account);
    }

    /**
     * @param  list<RemoteMessage>  $messages
     * @return list<int>
     */
    private function threadIds(array $messages, MailAccount $account): array
    {
        if ($messages === []) {
            return [];
        }

        return $account->messages()
            ->whereIn('provider_message_id', array_map(fn ($m) => $m->providerMessageId, $messages))
            ->distinct()
            ->pluck('thread_id')
            ->all();
    }
}

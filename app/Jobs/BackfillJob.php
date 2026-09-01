<?php

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Mail\Contracts\MailboxProvider;
use App\Mail\Data\RemoteMessage;
use App\Mail\Exceptions\AuthenticationFailedException;
use App\Mail\Support\MessageWriter;
use App\Models\Folder;
use App\Models\MailAccount;
use App\Models\SyncFailure;
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

    // A removed account leaves its queued jobs behind; they should vanish with it
    // rather than land in failed_jobs complaining about a model that is gone.
    public bool $deleteWhenMissingModels = true;

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
        // Refresh the folder list whenever a walk starts from scratch — the first
        // connect AND every full resync. Labels created after connect must become
        // folders, or their messages have nowhere to be filed.
        if ($account->folders()->doesntExist() || ($account->sync_cursor ?? []) === []) {
            $writer->storeFolders($account, $driver->listFolders($account));
        }

        // Take the delta cursor BEFORE reading any message, and take it on EVERY
        // walk that starts without one — not only the first. A full resync clears
        // the cursor but keeps the folders; capturing only on first connect left a
        // recovered account cursorless, so every following scheduler tick triggered
        // another full resync, forever.
        if (($account->sync_cursor ?? []) === []) {
            $account->update(['sync_cursor' => $driver->currentCursor($account)->toArray()]);
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

        // One poisoned message must not wedge the account: quarantine it and keep
        // walking. A page where EVERY message fails is not poison but something
        // systemic (database down, schema drift), so that still throws and retries.
        $failed = 0;
        $lastFailure = null;

        foreach ($page->messages as $remote) {
            try {
                $writer->store($account, $remote, recount: false);
            } catch (\Throwable $e) {
                SyncFailure::record($account, $remote->providerMessageId, $e);
                Log::warning('Message quarantined during backfill', [
                    'account' => $account->email,
                    'message' => $remote->providerMessageId,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
                $lastFailure = $e;
            }
        }

        if ($failed > 1 && $failed === count($page->messages)) {
            throw $lastFailure;
        }

        foreach ($this->threadIds($page->messages, $account) as $threadId) {
            $writer->recountThread($threadId);
        }

        if ($page->hasMore()) {
            $folder->update(['backfill_cursor' => $page->nextCursor]);
        } else {
            $folder->update(['backfill_cursor' => null, 'backfill_done_at' => now()]);
        }

        // Progress heartbeat: the sidebar and the staleness banner read
        // last_synced_at, and a long first import that never touches it reports
        // "Synced never" for hours while mail is visibly arriving.
        $account->update(['last_synced_at' => now()]);

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

<?php

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Mail\Data\SyncCursor;
use App\Mail\Exceptions\AuthenticationFailedException;
use App\Mail\Exceptions\CursorInvalidException;
use App\Mail\Support\MessageWriter;
use App\Models\MailAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * One incremental sync pass over one account.
 *
 * Dispatched by the scheduler for polled accounts and by the IDLE daemon for IMAP
 * accounts. Both paths are safe to run concurrently in the sense that the overlap
 * lock makes only one of them actually work at a time.
 */
class SyncAccountJob implements ShouldQueue
{
    use Queueable;

    // A removed account leaves its queued jobs behind; they should vanish with it
    // rather than land in failed_jobs complaining about a model that is gone.
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public function __construct(public readonly MailAccount $account) {}

    /**
     * Without this lock the scheduler's tick and an IDLE-triggered dispatch can
     * sync the same account at once, doubling provider calls and racing on the
     * cursor write.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->account->id))->dontRelease()->expireAfter(600)];
    }

    public function handle(MessageWriter $writer): void
    {
        $account = $this->account->fresh();

        if ($account === null || ! $account->status->shouldSync()) {
            return;
        }

        // Nothing incremental to do until there is a baseline to be incremental from.
        if (! $account->hasFinishedBackfill()) {
            BackfillJob::dispatch($account);

            return;
        }

        $driver = $account->driver();
        $cursor = new SyncCursor($account->sync_cursor ?? []);

        if ($cursor->isEmpty()) {
            FullResyncJob::dispatch($account);

            return;
        }

        try {
            $changes = $driver->fetchChanges($account, $cursor);
        } catch (CursorInvalidException $e) {
            // The provider forgot where we were. Reconciling by hand is not possible;
            // a full resync is the documented and only recovery.
            Log::warning('Sync cursor expired, falling back to full resync', [
                'account' => $account->email,
                'provider' => $account->provider->value,
                'reason' => $e->getMessage(),
            ]);

            FullResyncJob::dispatch($account);

            return;
        } catch (AuthenticationFailedException $e) {
            $account->update([
                'status' => AccountStatus::AuthError,
                'last_error' => $e->getMessage(),
            ]);

            // Retrying cannot fix a revoked token or a rotated app password.
            $this->fail($e);

            return;
        }

        $applied = $writer->applyChangeSet($account, $changes);

        // Advance the cursor only after the changes it describes are durably stored.
        // The other order loses a whole window of mail if persistence throws.
        $account->update([
            'sync_cursor' => $changes->cursor?->toArray() ?? $cursor->toArray(),
            'last_synced_at' => now(),
            'last_error' => null,
        ]);

        if (! $changes->isEmpty()) {
            Log::info('Synced account', ['account' => $account->email, ...$applied]);
        }
    }
}

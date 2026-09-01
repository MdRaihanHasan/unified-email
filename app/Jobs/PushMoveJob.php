<?php

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Enums\MoveAction;
use App\Mail\Exceptions\AuthenticationFailedException;
use App\Mail\Support\MessageWriter;
use App\Models\MailAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes an archive/trash/spam/restore up to the provider.
 *
 * Same shape as PushFlagsJob: the UI already moved the local pivots so the
 * thread left the list instantly, and if the provider ultimately refuses, the
 * captured pivot rows are put back — local state must never drift from the
 * provider's with nothing to reconcile them.
 */
class PushMoveJob implements ShouldQueue
{
    use Queueable;

    // A removed account leaves its queued jobs behind; they should vanish with it
    // rather than land in failed_jobs complaining about a model that is gone.
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    /**
     * @param  list<string>  $providerMessageIds
     * @param  array<string, list<int>>  $previousFolders  provider message id => folder ids before the move
     */
    public function __construct(
        public readonly MailAccount $account,
        public readonly array $providerMessageIds,
        public readonly MoveAction $action,
        public readonly array $previousFolders = [],
    ) {
        // Triage is something the user just did and is watching for; it must not
        // queue behind an hour of backfill pages.
        $this->onQueue('interactive');
    }

    public function handle(): void
    {
        if ($this->providerMessageIds === []) {
            return;
        }

        $this->account->driver()->applyMove($this->account, $this->providerMessageIds, $this->action);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof AuthenticationFailedException) {
            $this->account->update([
                'status' => AccountStatus::AuthError,
                'last_error' => $exception->getMessage(),
            ]);
        }

        Log::warning('Move push failed, restoring local folders', [
            'account' => $this->account->email,
            'action' => $this->action->value,
            'messages' => count($this->providerMessageIds),
            'reason' => $exception?->getMessage(),
        ]);

        $writer = app(MessageWriter::class);

        $messages = $this->account->messages()
            ->whereIn('provider_message_id', $this->providerMessageIds)
            ->get();

        foreach ($messages as $message) {
            $message->folders()->sync($this->previousFolders[$message->provider_message_id] ?? []);
        }

        foreach ($messages->pluck('thread_id')->unique() as $threadId) {
            $writer->recountThread($threadId);
        }
    }
}

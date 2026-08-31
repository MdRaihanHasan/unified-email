<?php

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Mail\Data\FlagChange;
use App\Mail\Exceptions\AuthenticationFailedException;
use App\Mail\Support\MessageWriter;
use App\Models\MailAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes a local read/star change up to the provider.
 *
 * The UI writes to Postgres first and queues this, so marking mail read stays
 * instant. If the push ultimately fails the local flag is put back: leaving it
 * would drift our state from the provider's with nothing to reconcile them, and
 * the next delta sync would not notice because the provider never changed.
 */
class PushFlagsJob implements ShouldQueue
{
    use Queueable;

    // A removed account leaves its queued jobs behind; they should vanish with it
    // rather than land in failed_jobs complaining about a model that is gone.
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    /**
     * @param  list<string>  $providerMessageIds
     * @param  array<string, bool>  $previous  provider message id => flag value before the change
     */
    public function __construct(
        public readonly MailAccount $account,
        public readonly array $providerMessageIds,
        public readonly FlagChange $change,
        public readonly array $previous = [],
    ) {
        // A read/star toggle is something the user just did and is watching for;
        // it must not queue behind an hour of backfill pages.
        $this->onQueue('interactive');
    }

    public function handle(): void
    {
        if ($this->change->isEmpty() || $this->providerMessageIds === []) {
            return;
        }

        $this->account->driver()->applyFlags($this->account, $this->providerMessageIds, $this->change);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof AuthenticationFailedException) {
            $this->account->update([
                'status' => AccountStatus::AuthError,
                'last_error' => $exception->getMessage(),
            ]);
        }

        Log::warning('Flag push failed, reverting local flags', [
            'account' => $this->account->email,
            'messages' => count($this->providerMessageIds),
            'reason' => $exception?->getMessage(),
        ]);

        $this->revert(app(MessageWriter::class));
    }

    private function revert(MessageWriter $writer): void
    {
        $messages = $this->account->messages()
            ->whereIn('provider_message_id', $this->providerMessageIds)
            ->get();

        foreach ($messages as $message) {
            $attributes = [];

            if ($this->change->isRead !== null) {
                $attributes['is_read'] = $this->previous[$message->provider_message_id]['is_read']
                    ?? ! $this->change->isRead;
            }

            if ($this->change->isStarred !== null) {
                $attributes['is_starred'] = $this->previous[$message->provider_message_id]['is_starred']
                    ?? ! $this->change->isStarred;
            }

            if ($attributes !== []) {
                $message->update($attributes);
            }
        }

        foreach ($messages->pluck('thread_id')->unique() as $threadId) {
            $writer->recountThread($threadId);
        }
    }
}

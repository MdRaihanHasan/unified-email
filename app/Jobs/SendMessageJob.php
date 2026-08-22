<?php

namespace App\Jobs;

use App\Enums\AccountStatus;
use App\Enums\OutboundStatus;
use App\Mail\Exceptions\AuthenticationFailedException;
use App\Mail\Support\MimeBuilder;
use App\Mail\Support\OutboundDraftFactory;
use App\Models\OutboundMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hands one draft to its account's provider.
 *
 * The sent copy is not written locally: every provider files it in Sent itself, so
 * the ordinary sync brings it back. Inserting our own copy would either duplicate
 * that or race with it.
 */
class SendMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [15, 60, 300];

    public function __construct(public readonly OutboundMessage $outbound) {}

    /** A retry must never send the message a second time. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('send:'.$this->outbound->id))->dontRelease()->expireAfter(300)];
    }

    public function handle(OutboundDraftFactory $factory, MimeBuilder $mime): void
    {
        $outbound = $this->outbound->fresh();

        if ($outbound === null || $outbound->status === OutboundStatus::Sent) {
            return;
        }

        $account = $outbound->mailAccount;

        // Generate the Message-ID once and keep it, so a retry reuses the same one
        // and the recipient's client treats a duplicate delivery as one message.
        if ($outbound->rfc822_message_id === null) {
            $outbound->update(['rfc822_message_id' => $mime->generateMessageId($account)]);
        }

        $outbound->update([
            'status' => OutboundStatus::Sending,
            'attempts' => $outbound->attempts + 1,
        ]);

        $result = $account->driver()->send($account, $factory->from($outbound));

        $outbound->update([
            'status' => OutboundStatus::Sent,
            'sent_message_id' => $result->providerMessageId,
            'rfc822_message_id' => $result->rfc822MessageId ?? $outbound->rfc822_message_id,
            'sent_at' => $result->sentAt ?? now(),
            'error' => null,
        ]);

        // Pull the Sent copy in promptly instead of waiting for the next tick, so
        // the message appears in its thread right after sending.
        SyncAccountJob::dispatch($account);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof AuthenticationFailedException) {
            $this->outbound->mailAccount->update([
                'status' => AccountStatus::AuthError,
                'last_error' => $exception->getMessage(),
            ]);
        }

        $this->outbound->update([
            'status' => OutboundStatus::Failed,
            'error' => $exception?->getMessage(),
        ]);

        Log::warning('Send failed', [
            'outbound' => $this->outbound->id,
            'account' => $this->outbound->mailAccount->email,
            'reason' => $exception?->getMessage(),
        ]);
    }
}

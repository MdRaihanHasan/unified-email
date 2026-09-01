<?php

namespace App\Jobs;

use App\Mail\Support\MessageWriter;
use App\Models\MailAccount;
use App\Models\Thread;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a mailbox in chunks, off the request path.
 *
 * Cascading 100k rows inside one HTTP request is a timeout with partial
 * cleanup; here the deletes are chunked, resumable (a re-run just continues —
 * everything is keyed on what still exists), and the threads that survive a
 * cross-account stitch get their counters redone at the end.
 */
class RemoveAccountJob implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(public readonly MailAccount $account) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('remove:'.$this->account->id))->dontRelease()->expireAfter(1800)];
    }

    public function handle(MessageWriter $writer): void
    {
        $account = $this->account->fresh();

        if ($account === null) {
            return;
        }

        // Staged compose attachments live on disk, outside the database cascade.
        foreach ($account->outboundMessages()->whereNotNull('attachments')->pluck('attachments') as $staged) {
            foreach ($staged ?? [] as $attachment) {
                if (! empty($attachment['path'])) {
                    Storage::disk('local')->delete($attachment['path']);
                }
            }
        }

        // Downloaded attachment files, same story.
        foreach ($account->messages()->join('attachments', 'attachments.message_id', '=', 'messages.id')
            ->whereNotNull('attachments.disk_path')->pluck('attachments.disk_path') as $path) {
            Storage::disk('local')->delete($path);
        }

        // Captured before the delete: afterwards nothing says which threads were touched.
        $threadIds = $account->messages()->distinct()->pluck('thread_id');

        // Chunked so no single statement holds a giant lock; each chunk cascades
        // its attachments and pivot rows in the database.
        do {
            $deleted = $account->messages()->limit(2000)->delete();
        } while ($deleted > 0);

        $account->delete();

        // Threads whose only messages were this account's are now empty; ones
        // shared with another mailbox survive and need their counters redone.
        $threadIds->chunk(1000)->each(function ($chunk) use ($writer) {
            Thread::whereIn('id', $chunk)->whereDoesntHave('messages')->delete();
            Thread::whereIn('id', $chunk)->pluck('id')->each(fn (int $id) => $writer->recountThread($id));
        });

        Log::info('Account removed', ['email' => $account->email, 'threads_touched' => count($threadIds)]);
    }
}

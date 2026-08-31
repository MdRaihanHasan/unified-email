<?php

namespace App\Console\Commands;

use App\Mail\Support\MessageWriter;
use App\Models\Message;
use App\Models\Thread;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Repairs threads that the subject heuristic wrongly merged.
 *
 * Before the resolver learned to defer to the provider's own thread ids, every
 * recurring automated notification with the same subject collapsed into one giant
 * local thread (observed live: 144 unrelated "Application stuck in the queue"
 * mails as a single conversation). The resolver fix stops new damage; this command
 * undoes the existing damage.
 *
 * The split is deliberately conservative. A thread is only taken apart when
 *
 *   1. one account contributed two or more DIFFERENT provider thread ids — the
 *      provider itself says these are separate conversations, and
 *   2. no message references a message in another of those groups — header links
 *      are how legitimate merges happen (cross-account stitching, tier 1), and a
 *      thread joined by References is left exactly as it is.
 */
class RethreadCommand extends Command
{
    protected $signature = 'mail:rethread {--dry-run : Report what would be split without changing anything}';

    protected $description = 'Split threads that the subject heuristic merged against the provider\'s own threading';

    public function handle(MessageWriter $writer): int
    {
        $dry = (bool) $this->option('dry-run');
        $threadsSplit = 0;
        $threadsCreated = 0;

        // New threads created by a split get ids above this and are never revisited.
        $maxId = (int) Thread::query()->max('id');

        Thread::query()
            ->where('id', '<=', $maxId)
            ->orderBy('id')
            ->chunkById(200, function (Collection $threads) use (&$threadsSplit, &$threadsCreated, $writer, $dry) {
                foreach ($threads as $thread) {
                    $made = $this->split($thread, $writer, $dry);

                    if ($made > 0) {
                        $threadsSplit++;
                        $threadsCreated += $made;
                        $this->line(sprintf(
                            '  %s thread #%d "%s" → +%d thread%s',
                            $dry ? 'would split' : 'split',
                            $thread->id,
                            mb_strimwidth((string) $thread->subject, 0, 60, '…'),
                            $made,
                            $made === 1 ? '' : 's',
                        ));
                    }
                }
            });

        $this->components->info(sprintf(
            '%s%d merged thread%s found, %d new thread%s %s.',
            $dry ? '[dry run] ' : '',
            $threadsSplit,
            $threadsSplit === 1 ? '' : 's',
            $threadsCreated,
            $threadsCreated === 1 ? '' : 's',
            $dry ? 'would be created' : 'created',
        ));

        return self::SUCCESS;
    }

    /** @return int new threads created (or that would be, on --dry-run) */
    private function split(Thread $thread, MessageWriter $writer, bool $dry): int
    {
        $messages = $thread->messages()->get([
            'id', 'mail_account_id', 'provider_thread_id', 'rfc822_message_id',
            'in_reply_to', 'references_ids', 'received_at', 'sent_at', 'subject', 'snippet',
        ]);

        if ($messages->count() < 2) {
            return 0;
        }

        // Group key: the provider's own conversation, scoped to its account.
        // Messages without a provider thread id keep the original thread.
        $key = fn (Message $message): ?string => $message->provider_thread_id === null
            ? null
            : $message->mail_account_id.'|'.$message->provider_thread_id;

        $groups = $messages->filter(fn (Message $m) => $key($m) !== null)->groupBy($key);

        $accountHasConflict = $messages
            ->whereNotNull('provider_thread_id')
            ->groupBy('mail_account_id')
            ->contains(fn (Collection $group) => $group->pluck('provider_thread_id')->unique()->count() > 1);

        if (! $accountHasConflict) {
            return 0;
        }

        // Any header link BETWEEN groups means the merge was deliberate (tier 1).
        $groupOf = [];

        foreach ($messages as $message) {
            if ($message->rfc822_message_id !== null) {
                $groupOf[$message->rfc822_message_id] = $key($message) ?? 'root';
            }
        }

        foreach ($messages as $message) {
            $own = $key($message) ?? 'root';

            foreach ([$message->in_reply_to, ...($message->references_ids ?? [])] as $referenced) {
                if ($referenced !== null && isset($groupOf[$referenced]) && $groupOf[$referenced] !== $own) {
                    return 0;
                }
            }
        }

        // The group holding the oldest message keeps the original thread row, so
        // deep links to the thread keep pointing at the older conversation.
        $oldest = $messages->sortBy(fn (Message $m) => $m->received_at ?? $m->sent_at)->first();
        $keep = $key($oldest) ?? 'root';

        $created = 0;

        foreach ($groups as $groupKey => $group) {
            if ($groupKey === $keep) {
                continue;
            }

            $created++;

            if ($dry) {
                continue;
            }

            $first = $group->sortBy(fn (Message $m) => $m->received_at ?? $m->sent_at)->first();
            $at = $first->received_at ?? $first->sent_at ?? now();

            $new = Thread::create([
                'subject' => $first->subject,
                'subject_normalized' => Thread::normalizeSubject($first->subject),
                'snippet' => $first->snippet,
                'participants' => [],
                'first_message_at' => $at,
                'last_message_at' => $at,
                'message_count' => 0,
                'unread_count' => 0,
            ]);

            Message::whereIn('id', $group->pluck('id'))->update(['thread_id' => $new->id]);
            $writer->recountThread($new->id);
        }

        if (! $dry && $created > 0) {
            $writer->recountThread($thread->id);
        }

        return $created;
    }
}

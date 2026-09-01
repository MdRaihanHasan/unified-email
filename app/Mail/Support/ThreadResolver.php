<?php

namespace App\Mail\Support;

use App\Mail\Data\RemoteMessage;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\OutboundMessage;
use App\Models\Thread;

/**
 * Decides which thread an incoming message belongs to.
 *
 * Three tiers, in order of trustworthiness:
 *
 *   1. RFC 5322 In-Reply-To / References. Message-IDs are globally unique, so this
 *      is the only tier allowed to merge across accounts — and it is what makes a
 *      Gmail reply land on the same thread as the Outlook original.
 *   2. The provider's own thread id (Gmail threadId, Graph conversationId). Reliable,
 *      but only ever within the one account that issued it.
 *   3. Normalised subject plus overlapping participants inside a time window. A
 *      heuristic, deliberately never applied across accounts: two unrelated people
 *      mailing "Invoice" to two different mailboxes must not become one thread.
 */
class ThreadResolver
{
    public function __construct(
        private readonly int $subjectWindowDays = 30,
    ) {}

    public function resolve(MailAccount $account, RemoteMessage $remote): Thread
    {
        return $this->byReferences($remote)
            ?? $this->byProviderThreadId($account, $remote)
            ?? $this->bySubjectHeuristic($account, $remote)
            ?? $this->create($remote);
    }

    /** Tier 1 — headers. Cross-account merging happens here and only here. */
    private function byReferences(RemoteMessage $remote): ?Thread
    {
        // Ancestors — and our own Message-ID: another mailbox's copy of this very
        // message (the Sent original beside the received copy) shares it, and the
        // two copies must be one thread even when neither carries References.
        $candidates = array_filter([
            $remote->inReplyTo,
            ...array_reverse($remote->references),
            $remote->rfc822MessageId,
        ]);

        $threadIds = $candidates === []
            ? collect()
            : Message::query()
                ->whereIn('rfc822_message_id', $candidates)
                ->distinct()
                ->pluck('thread_id');

        // We may instead be the *parent* of something already stored: two accounts
        // poll independently, so a reply can be synced before the message it answers.
        // This runs even when we have no References of our own — a thread root has
        // none by definition, and that is exactly the case that needs it.
        if ($remote->rfc822MessageId !== null) {
            $threadIds = $threadIds->merge(Message::query()
                ->where(fn ($query) => $query
                    ->whereJsonContains('references_ids', $remote->rfc822MessageId)
                    ->orWhere('in_reply_to', $remote->rfc822MessageId))
                ->distinct()
                ->pluck('thread_id'));
        }

        $threadIds = $threadIds->unique()->values();

        return match (true) {
            $threadIds->isEmpty() => null,
            $threadIds->count() === 1 => Thread::find($threadIds->first()),
            // The evidence says these are one conversation that resolved apart —
            // an unlucky cross-account arrival order, or truncated References that
            // later filled in. Converge now, while the proof is in hand.
            default => $this->mergeThreads($threadIds->all()),
        };
    }

    /**
     * Merge threads the headers have proven to be one conversation. The largest
     * survives; the rest hand over their messages and outbound drafts and vanish.
     * Counters are not touched here — every caller recounts the returned thread
     * after storing, and the losers no longer exist to be wrong.
     *
     * @param  list<int>  $threadIds
     */
    private function mergeThreads(array $threadIds): ?Thread
    {
        $winner = Thread::query()
            ->whereIn('id', $threadIds)
            ->withCount('messages')
            ->orderByDesc('messages_count')
            ->orderBy('id')
            ->first();

        if ($winner === null) {
            return null;
        }

        $losers = array_values(array_diff($threadIds, [$winner->id]));

        Message::whereIn('thread_id', $losers)->update(['thread_id' => $winner->id]);
        OutboundMessage::whereIn('thread_id', $losers)->update(['thread_id' => $winner->id]);
        Thread::whereIn('id', $losers)->delete();

        return $winner;
    }

    /** Tier 2 — the provider's own thread id, scoped to the issuing account. */
    private function byProviderThreadId(MailAccount $account, RemoteMessage $remote): ?Thread
    {
        if ($remote->providerThreadId === null || ! $account->provider->hasNativeThreadId()) {
            return null;
        }

        $threadId = Message::query()
            ->where('mail_account_id', $account->id)
            ->where('provider_thread_id', $remote->providerThreadId)
            ->value('thread_id');

        return $threadId === null ? null : Thread::find($threadId);
    }

    /** Tier 3 — subject and participants. Same account only, bounded time window. */
    private function bySubjectHeuristic(MailAccount $account, RemoteMessage $remote): ?Thread
    {
        // By the time tier 3 runs, the provider's own threading has already spoken:
        // tier 2 found no sibling for this thread id, so the provider considers the
        // message a NEW conversation. Overriding that by subject is how recurring
        // automated mail ("Payment reminder" from the same robot every day)
        // collapsed into one giant thread. The heuristic stays available where it
        // belongs: providers with no native thread id, and messages without one.
        if ($remote->providerThreadId !== null && $account->provider->hasNativeThreadId()) {
            return null;
        }

        $normalized = Thread::normalizeSubject($remote->subject);

        if ($normalized === '') {
            return null;
        }

        $sentAt = $remote->sentAt ?? $remote->receivedAt;
        $participants = $this->participants($remote);

        if ($participants === []) {
            return null;
        }

        $threads = Thread::query()
            ->where('subject_normalized', $normalized)
            ->when($sentAt !== null, fn ($query) => $query
                ->where('last_message_at', '>=', $sentAt->modify("-{$this->subjectWindowDays} days"))
                ->where('first_message_at', '<=', $sentAt->modify("+{$this->subjectWindowDays} days")))
            ->whereHas('messages', fn ($query) => $query->where('mail_account_id', $account->id))
            ->latest('last_message_at')
            ->limit(20)
            ->get();

        foreach ($threads as $thread) {
            if (array_intersect($participants, $thread->participants ?? []) !== []) {
                return $thread;
            }
        }

        return null;
    }

    private function create(RemoteMessage $remote): Thread
    {
        $at = $remote->sentAt ?? $remote->receivedAt ?? now();

        return Thread::create([
            'subject' => $remote->subject,
            'subject_normalized' => Thread::normalizeSubject($remote->subject),
            'snippet' => $remote->snippet,
            'participants' => $this->participants($remote),
            'first_message_at' => $at,
            'last_message_at' => $at,
            'message_count' => 0,
            'unread_count' => 0,
        ]);
    }

    /** @return list<string> lowercased addresses */
    public function participants(RemoteMessage $remote): array
    {
        $addresses = [];

        foreach ([[$remote->from], $remote->to, $remote->cc] as $group) {
            foreach ($group as $address) {
                if ($address !== null && $address->address !== '') {
                    $addresses[] = mb_strtolower($address->address);
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}

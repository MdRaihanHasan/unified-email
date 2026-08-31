<?php

namespace App\Mail\Support;

use App\Enums\FolderRole;
use App\Mail\Data\ChangeSet;
use App\Mail\Data\MessageUpdate;
use App\Mail\Data\RemoteFolder;
use App\Mail\Data\RemoteMessage;
use App\Models\Folder;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\Thread;
use Illuminate\Support\Facades\DB;

/**
 * Turns provider data into rows.
 *
 * Every write here has to be safe to repeat. Jobs retry, webhooks arrive twice,
 * and a full resync deliberately re-walks a mailbox we already hold — so this is
 * built around upserts keyed on (mail_account_id, provider_message_id) rather than
 * inserts guarded by existence checks, which would race.
 */
class MessageWriter
{
    public function __construct(private readonly ThreadResolver $threads) {}

    /**
     * Apply a whole sync pass.
     *
     * @return array{created: int, updated: int, deleted: int}
     */
    public function applyChangeSet(MailAccount $account, ChangeSet $changes): array
    {
        $created = 0;
        $touchedThreads = [];

        foreach ($changes->created as $remote) {
            $message = $this->store($account, $remote, recount: false);
            $touchedThreads[$message->thread_id] = true;
            $created++;
        }

        $updated = 0;

        foreach ($changes->updated as $update) {
            $message = $this->applyUpdate($account, $update);

            if ($message !== null) {
                $touchedThreads[$message->thread_id] = true;
                $updated++;
            }
        }

        $deleted = $this->delete($account, $changes->deletedIds, $touchedThreads);

        // Recount once per thread at the end rather than per message: a 500-message
        // backfill page otherwise re-aggregates the same thread hundreds of times.
        foreach (array_keys($touchedThreads) as $threadId) {
            $this->recountThread($threadId);
        }

        return ['created' => $created, 'updated' => $updated, 'deleted' => $deleted];
    }

    /**
     * Store one message, or refresh the copy we already have.
     */
    public function store(MailAccount $account, RemoteMessage $remote, bool $recount = true): Message
    {
        $message = DB::transaction(function () use ($account, $remote) {
            $existing = Message::query()
                ->where('mail_account_id', $account->id)
                ->where('provider_message_id', $remote->providerMessageId)
                ->first();

            // Keep the thread we already assigned. Re-resolving on a resync could
            // move a message after a sibling has since arrived and changed what the
            // heuristics would pick, silently splitting a conversation.
            $thread = $existing !== null
                ? $existing->thread
                : $this->threads->resolve($account, $remote);

            $attributes = [
                'thread_id' => $thread->id,
                'provider_thread_id' => $remote->providerThreadId,
                'rfc822_message_id' => $remote->rfc822MessageId,
                'in_reply_to' => $remote->inReplyTo,
                'references_ids' => $remote->references,
                'from_addr' => $remote->from,
                'to_addrs' => $remote->to,
                'cc_addrs' => $remote->cc,
                'bcc_addrs' => $remote->bcc,
                'reply_to' => $remote->replyTo,
                'subject' => $remote->subject,
                'snippet' => $remote->snippet,
                'sent_at' => $remote->sentAt,
                'received_at' => $remote->receivedAt ?? $remote->sentAt,
                'is_read' => $remote->isRead,
                'is_starred' => $remote->isStarred,
                'is_draft' => $remote->isDraft,
                'is_answered' => $remote->isAnswered,
                'size_bytes' => $remote->sizeBytes,
                'has_attachments' => $remote->hasAttachments(),
                'headers' => $remote->headers,
            ];

            // Bodies arrive later, on demand. Never overwrite a fetched body with the
            // null a headers-only sync carries.
            if ($remote->bodyHtml !== null || $remote->bodyText !== null) {
                $attributes['body_html'] = $remote->bodyHtml;
                $attributes['body_text'] = $remote->bodyText;
            }

            $message = Message::updateOrCreate(
                [
                    'mail_account_id' => $account->id,
                    'provider_message_id' => $remote->providerMessageId,
                ],
                $attributes,
            );

            $this->syncFolders($account, $message, $remote->folderRemoteIds);
            $this->syncAttachments($message, $remote);

            return $message;
        });

        if ($recount) {
            $this->recountThread($message->thread_id);
        }

        return $message;
    }

    /** Apply a partial flag/folder change. Nulls mean "provider did not say", not "false". */
    public function applyUpdate(MailAccount $account, MessageUpdate $update): ?Message
    {
        $message = Message::query()
            ->where('mail_account_id', $account->id)
            ->where('provider_message_id', $update->providerMessageId)
            ->first();

        // An update for a message we never stored is normal: it may predate the
        // backfill window. Nothing to do, and nothing wrong.
        if ($message === null) {
            return null;
        }

        $changes = array_filter([
            'is_read' => $update->isRead,
            'is_starred' => $update->isStarred,
        ], fn ($value) => $value !== null);

        if ($changes !== []) {
            $message->update($changes);
        }

        if ($update->folderRemoteIds !== null) {
            $this->syncFolders($account, $message, $update->folderRemoteIds);
        }

        return $message;
    }

    /**
     * @param  list<string>  $providerMessageIds
     * @param  array<int, bool>  $touchedThreads  collected by reference for recounting
     */
    public function delete(MailAccount $account, array $providerMessageIds, array &$touchedThreads = []): int
    {
        if ($providerMessageIds === []) {
            return 0;
        }

        $messages = Message::query()
            ->where('mail_account_id', $account->id)
            ->whereIn('provider_message_id', $providerMessageIds)
            ->get(['id', 'thread_id']);

        foreach ($messages as $message) {
            $touchedThreads[$message->thread_id] = true;
        }

        Message::whereIn('id', $messages->pluck('id'))->delete();

        return $messages->count();
    }

    /**
     * @param  list<string>  $folderRemoteIds
     */
    private function syncFolders(MailAccount $account, Message $message, array $folderRemoteIds): void
    {
        // An empty list is a real state, not "unknown". Archiving in Gmail removes
        // the message's last tracked label, and detaching here is the only way it
        // ever leaves the inbox — callers express "provider did not say" as null,
        // never as []. Skipping the empty set left archived mail in the inbox
        // forever.
        $ids = Folder::query()
            ->where('mail_account_id', $account->id)
            ->whereIn('remote_id', $folderRemoteIds)
            ->pluck('id', 'remote_id');

        // A label this app has never seen — created in Gmail after connect — still
        // has to file the message somewhere, or sync() below would silently orphan
        // it out of every view. The row starts bare, named by its id; the next
        // folder refresh fills in the real name.
        foreach ($folderRemoteIds as $remoteId) {
            if (! isset($ids[$remoteId])) {
                $ids[$remoteId] = Folder::firstOrCreate(
                    ['mail_account_id' => $account->id, 'remote_id' => $remoteId],
                    ['name' => $remoteId, 'path' => $remoteId, 'role' => FolderRole::Custom, 'is_label' => true],
                )->id;
            }
        }

        // sync() rather than attach(): for Gmail this is the only signal that a label
        // was *removed*, since a message keeps its id and just reports fewer labels.
        $message->folders()->sync($ids->values()->all());
    }

    private function syncAttachments(Message $message, RemoteMessage $remote): void
    {
        if ($remote->attachments === []) {
            return;
        }

        foreach ($remote->attachments as $attachment) {
            $message->attachments()->updateOrCreate(
                [
                    'filename' => $attachment->filename,
                    'content_id' => $attachment->contentId,
                ],
                [
                    'remote_id' => $attachment->remoteId,
                    'mime_type' => $attachment->mimeType,
                    'size_bytes' => $attachment->sizeBytes,
                    'is_inline' => $attachment->isInline,
                ],
            );
        }
    }

    /**
     * Recompute a thread's denormalised counters from its messages.
     *
     * These drive the inbox list, so they are derived rather than incremented:
     * an incremented counter drifts the first time a job retries, and there is no
     * way to notice it has.
     */
    public function recountThread(int $threadId): void
    {
        $thread = Thread::find($threadId);

        if ($thread === null) {
            return;
        }

        $messages = $thread->messages()
            ->orderBy('received_at')
            ->get(['subject', 'snippet', 'from_addr', 'to_addrs', 'cc_addrs', 'received_at', 'sent_at', 'is_read', 'is_starred', 'has_attachments']);

        if ($messages->isEmpty()) {
            $thread->delete();

            return;
        }

        $latest = $messages->last();
        $participants = [];

        foreach ($messages as $message) {
            foreach ([[$message->from_addr], $message->to_addrs ?? [], $message->cc_addrs ?? []] as $group) {
                foreach ($group as $address) {
                    if (is_array($address) && ! empty($address['address'])) {
                        $participants[] = mb_strtolower($address['address']);
                    }
                }
            }
        }

        $thread->update([
            'subject' => $messages->first()->subject,
            'subject_normalized' => Thread::normalizeSubject($messages->first()->subject),
            'snippet' => $latest->snippet,
            'participants' => array_values(array_unique($participants)),
            'first_message_at' => $messages->first()->received_at ?? $messages->first()->sent_at,
            'last_message_at' => $latest->received_at ?? $latest->sent_at,
            'message_count' => $messages->count(),
            'unread_count' => $messages->where('is_read', false)->count(),
            'has_attachments' => $messages->contains('has_attachments', true),
            'is_starred' => $messages->contains('is_starred', true),
        ]);
    }

    /**
     * Upsert the folder list for an account.
     *
     * @param  list<RemoteFolder>  $folders
     * @return array<string, Folder> keyed by remote id
     */
    public function storeFolders(MailAccount $account, array $folders): array
    {
        $stored = [];

        foreach ($folders as $folder) {
            $stored[$folder->remoteId] = Folder::updateOrCreate(
                [
                    'mail_account_id' => $account->id,
                    'remote_id' => $folder->remoteId,
                ],
                [
                    'name' => $folder->name,
                    'path' => $folder->path,
                    'role' => $folder->role,
                    'is_label' => $folder->isLabel,
                    'is_selectable' => $folder->isSelectable,
                    'total_count' => $folder->totalCount,
                    'unread_count' => $folder->unreadCount,
                ],
            );
        }

        return $stored;
    }
}

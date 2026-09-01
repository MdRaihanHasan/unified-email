<?php

namespace App\Http\Controllers;

use App\Enums\FolderRole;
use App\Enums\OutboundStatus;
use App\Mail\Support\HtmlSanitizer;
use App\Mail\Support\SearchQueryParser;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\OutboundMessage;
use App\Models\Thread;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The inbox is one screen: list on the left, the open thread on the right.
 *
 * Both routes render the same component. /inbox?thread=N is what clicking a row
 * produces, and /threads/N stays a working deep link rather than a second page —
 * making every open a navigation and every back another one is the thing this
 * layout exists to stop.
 */
class InboxController
{
    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'account' => ['nullable', 'integer', 'exists:mail_accounts,id'],
            'view' => ['nullable', 'in:inbox,unread,starred,sent,junk,trash,all'],
            'q' => ['nullable', 'string', 'max:200'],
            'thread' => ['nullable', 'integer', 'exists:threads,id'],
        ]);

        $selected = isset($filters['thread']) ? Thread::find($filters['thread']) : null;

        return $this->render($request, $filters, $selected);
    }

    /** Deep link. Same screen, with the thread already open. */
    public function show(Request $request, Thread $thread): Response
    {
        $filters = $request->validate([
            'account' => ['nullable', 'integer', 'exists:mail_accounts,id'],
            'view' => ['nullable', 'in:inbox,unread,starred,sent,junk,trash,all'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->render($request, [...$filters, 'thread' => $thread->id], $thread);
    }

    private function render(Request $request, array $filters, ?Thread $selected): Response
    {
        $view = $filters['view'] ?? 'inbox';

        // The search box says "all mailboxes" — make that true. A search covers
        // everything except Trash/Spam (Gmail's default), whatever view is open;
        // in:trash / in:spam / in:inbox narrows it explicitly.
        if (filled($filters['q'] ?? null)) {
            $view = app(SearchQueryParser::class)
                ->parse($filters['q'])['in'] ?? 'all';
        }

        $accounts = MailAccount::query()->pluck('provider', 'id');
        $ownAddresses = MailAccount::query()->pluck('email')
            ->map(fn (string $email) => mb_strtolower($email))
            ->all();

        $threads = Thread::query()
            ->inView($view)
            ->forAccount($filters['account'] ?? null)
            ->matching($filters['q'] ?? null)
            ->with(['messages:id,thread_id,mail_account_id,from_addr,received_at'])
            ->orderByDesc('last_message_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Thread $thread) => [
                'id' => $thread->id,
                'subject' => $thread->subject ?: '(no subject)',
                'snippet' => $thread->snippet,
                // Display names, not addresses: "Anna Bergström", not "anna". The
                // stored participants list holds addresses because thread matching
                // compares those, so the names come from the messages themselves.
                'participants' => $this->counterparties($thread, $ownAddresses),
                // A merged inbox hides which mailbox a thread arrived in, so put it
                // back. A thread stitched across accounts reports more than one.
                'providers' => $thread->messages
                    ->map(fn ($message) => $accounts[$message->mail_account_id]?->value)
                    ->filter()->unique()->values(),
                'message_count' => $thread->message_count,
                'unread_count' => $thread->unread_count,
                'has_attachments' => $thread->has_attachments,
                'is_starred' => $thread->is_starred,
                'last_message_at' => $thread->last_message_at?->toIso8601String(),
            ]);

        return Inertia::render('Inbox/Index', [
            'threads' => $threads,
            'filters' => [
                'view' => $view,
                'account' => $filters['account'] ?? null,
                'q' => $filters['q'] ?? null,
                'thread' => $selected?->id,
            ],
            // Only the open thread's body is loaded, and only when one is open.
            'open' => $selected === null ? null : $this->openThread($request, $selected),
        ]);
    }

    /**
     * Who this thread is with, for the list's "who" column.
     *
     * Senders other than us, newest first, by display name. Our own addresses come
     * out — otherwise every row in a merged inbox reads "…, me". A thread we only
     * ever sent falls back to who we sent it to.
     *
     * @param  list<string>  $ownAddresses  lowercased
     * @return list<string>
     */
    private function counterparties(Thread $thread, array $ownAddresses): array
    {
        $isOurs = fn (?array $address) => $address === null
            || empty($address['address'])
            || in_array(mb_strtolower($address['address']), $ownAddresses, true);

        $names = $thread->messages
            ->sortByDesc('received_at')
            ->map(fn (Message $message) => $message->from_addr)
            ->reject($isOurs)
            ->unique(fn (array $address) => mb_strtolower($address['address']))
            ->map(fn (array $address) => $this->displayName($address))
            ->values()
            ->all();

        if ($names !== []) {
            return $names;
        }

        // Everything here is from us, so the useful name is the other side's.
        $fallback = array_values(array_diff($thread->participants ?? [], $ownAddresses));

        return array_map(fn (string $address) => $this->displayName(['address' => $address]), $fallback);
    }

    private function displayName(array $address): string
    {
        if (! empty($address['name'])) {
            return $address['name'];
        }

        $email = $address['address'] ?? '';

        return str_contains($email, '@') ? strtok($email, '@') : $email;
    }

    /** @return array{thread: array, messages: array, pending: array} */
    private function openThread(Request $request, Thread $thread): array
    {
        // Per message, so agreeing to load images in one does not silently enable
        // tracking pixels in the rest of the thread.
        $showImagesFor = $request->integer('show_images') ?: null;

        $thread->load([
            'messages' => fn ($query) => $query->orderBy('received_at'),
            'messages.attachments',
            'messages.mailAccount',
            'messages.folders',
        ]);

        return [
            'thread' => [
                'id' => $thread->id,
                'subject' => $thread->subject ?: '(no subject)',
                'message_count' => $thread->message_count,
                'is_starred' => $thread->is_starred,
                'providers' => $thread->messages
                    ->map(fn (Message $m) => [
                        'value' => $m->mailAccount->provider->value,
                        'label' => $m->mailAccount->label,
                    ])
                    ->unique('value')->values(),
            ],
            // One rendered card per RFC Message-ID: a cross-account thread holds a
            // copy per mailbox, and rendering both used to show every message twice.
            'messages' => $thread->messages
                ->groupBy(fn (Message $m) => $m->rfc822_message_id ?? 'row-'.$m->id)
                ->map(function ($copies) use ($showImagesFor) {
                    // Prefer the copy that has a body, then one that is not in
                    // trash/spam — the reader wants the living, fullest copy.
                    $message = $copies->sortByDesc(fn (Message $m) => ($m->body_html !== null || $m->body_text !== null ? 2 : 0)
                        + ($this->hiddenReason($m) === null ? 1 : 0))->first();

                    return $this->messageCard($message, $copies, $showImagesFor);
                })
                ->values(),
            // Mail we tried to send on this thread that has not landed. A send can
            // sit in retry backoff for minutes; without this the user clicks Send,
            // sees nothing, and cannot tell success from silent failure.
            'pending' => OutboundMessage::query()
                ->where('thread_id', $thread->id)
                ->whereIn('status', [
                    OutboundStatus::Draft, OutboundStatus::Queued,
                    OutboundStatus::Sending, OutboundStatus::Failed,
                ])
                ->orderBy('id')
                ->get()
                ->map(fn (OutboundMessage $outbound) => [
                    'id' => $outbound->id,
                    'status' => $outbound->status->value,
                    'to' => $outbound->to_addrs ?? [],
                    'attempts' => $outbound->attempts,
                    'error' => $outbound->error,
                ])->values(),
        ];
    }

    /** A trashed or junked message renders collapsed and labeled, never expanded. */
    private function hiddenReason(Message $message): ?string
    {
        return match (true) {
            $message->folders->contains(fn ($f) => $f->role === FolderRole::Trash) => 'trash',
            $message->folders->contains(fn ($f) => $f->role === FolderRole::Junk) => 'junk',
            $message->is_draft => 'draft',
            default => null,
        };
    }

    /**
     * One rendered message, standing in for every mailbox's copy of it.
     *
     * @param  Collection<int, Message>  $copies
     */
    private function messageCard(Message $message, $copies, ?int $showImagesFor): array
    {
        $body = $message->body_html !== null
            ? $this->sanitizer->sanitize($message->body_html, allowRemoteImages: $showImagesFor === $message->id)
            : ['html' => $this->sanitizer->fromText($message->body_text), 'blocked_images' => 0];

        // cid: references point at inline attachments of this same message,
        // so they are safe to show without the remote-image consent step —
        // rewrite them to the attachment endpoint, which streams and caches.
        foreach ($message->attachments as $attachment) {
            if ($attachment->content_id !== null) {
                $body['html'] = str_ireplace(
                    'cid:'.$attachment->content_id,
                    route('messages.attachments.show', [$message, $attachment]),
                    $body['html'],
                );
            }
        }

        $accountOf = fn (Message $m) => [
            'id' => $m->mailAccount->id,
            'label' => $m->mailAccount->label,
            'email' => $m->mailAccount->email,
            'provider' => $m->mailAccount->provider->value,
        ];

        return [
            'id' => $message->id,
            'hidden_reason' => $this->hiddenReason($message),
            'account' => $accountOf($message),
            // Every mailbox holding a copy, for the chip cluster. Flag flips fan
            // out to all of them server-side.
            'accounts' => $copies->map($accountOf)->unique('id')->values(),
            'from' => $message->from_addr,
            'to' => $message->to_addrs ?? [],
            'cc' => $message->cc_addrs ?? [],
            'subject' => $message->subject,
            'snippet' => $message->snippet,
            'body_html' => $body['html'],
            'blocked_images' => $body['blocked_images'],
            'has_body' => $message->body_html !== null || $message->body_text !== null,
            'received_at' => $message->received_at?->toIso8601String(),
            'is_read' => $message->is_read,
            'is_starred' => $message->is_starred,
            'attachments' => $message->attachments
                ->where('is_inline', false)
                ->map(fn ($attachment) => [
                    'id' => $attachment->id,
                    'filename' => $attachment->filename,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                    'url' => route('messages.attachments.show', [$message, $attachment], false),
                ])->values(),
        ];
    }
}

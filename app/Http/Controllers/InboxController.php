<?php

namespace App\Http\Controllers;

use App\Enums\OutboundStatus;
use App\Mail\Support\HtmlSanitizer;
use App\Models\MailAccount;
use App\Models\OutboundMessage;
use App\Models\Thread;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InboxController
{
    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'account' => ['nullable', 'integer', 'exists:mail_accounts,id'],
            'view' => ['nullable', 'in:inbox,unread,starred,sent,all'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $view = $filters['view'] ?? 'inbox';

        $accounts = MailAccount::query()->pluck('provider', 'id');
        $ownAddresses = MailAccount::query()->pluck('email')
            ->map(fn (string $email) => mb_strtolower($email))
            ->all();

        $threads = Thread::query()
            ->inView($view)
            ->forAccount($filters['account'] ?? null)
            ->matching($filters['q'] ?? null)
            ->with(['messages:id,thread_id,mail_account_id'])
            ->orderByDesc('last_message_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Thread $thread) => [
                'id' => $thread->id,
                'subject' => $thread->subject ?: '(no subject)',
                'snippet' => $thread->snippet,
                // The useful column is who we are talking to, so our own addresses
                // come out — otherwise every row reads "…, me".
                'participants' => array_values(array_diff($thread->participants ?? [], $ownAddresses)),
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
            'filters' => ['view' => $view, 'account' => $filters['account'] ?? null, 'q' => $filters['q'] ?? null],
        ]);
    }

    public function show(Request $request, Thread $thread): Response
    {
        // Per-message opt-in, so agreeing to load images in one message does not
        // silently enable tracking pixels in the rest of the thread.
        $showImagesFor = $request->integer('show_images') ?: null;

        $thread->load(['messages' => fn ($query) => $query->orderBy('received_at'), 'messages.attachments', 'messages.mailAccount']);

        // Anything we tried to send on this thread but that has not landed yet. A
        // send can spend minutes in retry backoff, and without this the user clicks
        // Send, sees nothing, and has no way to tell success from silent failure.
        $pending = OutboundMessage::query()
            ->where('thread_id', $thread->id)
            ->whereIn('status', [
                OutboundStatus::Draft,
                OutboundStatus::Queued,
                OutboundStatus::Sending,
                OutboundStatus::Failed,
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (OutboundMessage $outbound) => [
                'id' => $outbound->id,
                'status' => $outbound->status->value,
                'subject' => $outbound->subject,
                'to' => $outbound->to_addrs ?? [],
                'attempts' => $outbound->attempts,
                'error' => $outbound->error,
            ]);

        return Inertia::render('Inbox/Show', [
            'pending' => $pending,
            'thread' => [
                'id' => $thread->id,
                'subject' => $thread->subject ?: '(no subject)',
                'message_count' => $thread->message_count,
            ],
            'messages' => $thread->messages->map(function ($message) use ($showImagesFor) {
                $body = $message->body_html !== null
                    ? $this->sanitizer->sanitize($message->body_html, allowRemoteImages: $showImagesFor === $message->id)
                    : ['html' => $this->sanitizer->fromText($message->body_text), 'blocked_images' => 0];

                return [
                    'id' => $message->id,
                    'account' => [
                        'label' => $message->mailAccount->label,
                        'email' => $message->mailAccount->email,
                        'provider' => $message->mailAccount->provider->value,
                    ],
                    'from' => $message->from_addr,
                    'to' => $message->to_addrs ?? [],
                    'cc' => $message->cc_addrs ?? [],
                    'subject' => $message->subject,
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
                        ])->values(),
                ];
            }),
        ]);
    }
}

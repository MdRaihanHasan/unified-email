<?php

namespace App\Http\Controllers;

use App\Enums\OutboundStatus;
use App\Enums\OutboundType;
use App\Jobs\SendMessageJob;
use App\Mail\Support\QuoteBuilder;
use App\Mail\Support\RecipientResolver;
use App\Mail\Support\ReplyHeaders;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\OutboundMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ComposeController
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly QuoteBuilder $quotes,
    ) {}

    /** New message, or a reply/forward prefilled from an existing one. */
    public function create(Request $request): Response|RedirectResponse
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::enum(OutboundType::class)],
            'message' => ['nullable', 'integer', 'exists:messages,id'],
            'account' => ['nullable', 'integer', 'exists:mail_accounts,id'],
        ]);

        $type = OutboundType::tryFrom($data['type'] ?? '') ?? OutboundType::New;
        $parent = isset($data['message']) ? Message::with('mailAccount')->find($data['message']) : null;

        if ($type !== OutboundType::New && $parent === null) {
            return back()->with('message', 'That message is no longer available to reply to.');
        }

        $account = $this->sendingAccount($data['account'] ?? null, $parent);

        if ($account === null) {
            return redirect()->route('accounts')->with('message', 'Connect a mailbox before composing.');
        }

        return Inertia::render('Compose/Edit', [
            'draft' => $this->blankDraft($type, $account, $parent),
            'accounts' => MailAccount::query()->orderBy('id')->get()
                ->map(fn (MailAccount $a) => [
                    'id' => $a->id,
                    'label' => $a->label,
                    'email' => $a->email,
                    'provider' => $a->provider->value,
                ])->values(),
        ]);
    }

    /**
     * The same prefill as create(), as JSON.
     *
     * The inline reply box and the floating composer are not pages, so they cannot
     * take an Inertia render — but recipient resolution and quoting belong on the
     * server, not duplicated in JavaScript.
     */
    public function prefill(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::enum(OutboundType::class)],
            'message' => ['nullable', 'integer', 'exists:messages,id'],
            'account' => ['nullable', 'integer', 'exists:mail_accounts,id'],
        ]);

        $type = OutboundType::tryFrom($data['type'] ?? '') ?? OutboundType::New;
        $parent = isset($data['message']) ? Message::with('mailAccount')->find($data['message']) : null;

        if ($type !== OutboundType::New && $parent === null) {
            return response()->json(['message' => 'That message is no longer available to reply to.'], 422);
        }

        $account = $this->sendingAccount($data['account'] ?? null, $parent);

        if ($account === null) {
            return response()->json(['message' => 'Connect a mailbox before composing.'], 422);
        }

        return response()->json([
            'draft' => $this->blankDraft($type, $account, $parent),
            'accounts' => MailAccount::query()->orderBy('id')->get()
                ->map(fn (MailAccount $a) => [
                    'id' => $a->id,
                    'label' => $a->label,
                    'email' => $a->email,
                    'provider' => $a->provider->value,
                ])->values(),
        ]);
    }

    /** Persist the draft. Called by autosave as well as by the first save. */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateDraft($request);

        $outbound = OutboundMessage::create([
            ...$this->attributes($data),
            'status' => OutboundStatus::Draft,
        ]);

        return redirect()->route('compose.edit', $outbound);
    }

    public function edit(OutboundMessage $outbound): Response
    {
        return Inertia::render('Compose/Edit', [
            'draft' => [
                'id' => $outbound->id,
                'type' => $outbound->type->value,
                'mail_account_id' => $outbound->mail_account_id,
                'thread_id' => $outbound->thread_id,
                'in_reply_to_message_id' => $outbound->in_reply_to_message_id,
                'to' => $outbound->to_addrs ?? [],
                'cc' => $outbound->cc_addrs ?? [],
                'bcc' => $outbound->bcc_addrs ?? [],
                'subject' => $outbound->subject,
                'body_html' => $outbound->body_html,
                'attachments' => $outbound->attachments ?? [],
                'status' => $outbound->status->value,
                'error' => $outbound->error,
            ],
            'accounts' => MailAccount::query()->orderBy('id')->get()
                ->map(fn (MailAccount $a) => [
                    'id' => $a->id,
                    'label' => $a->label,
                    'email' => $a->email,
                    'provider' => $a->provider->value,
                ])->values(),
        ]);
    }

    public function update(Request $request, OutboundMessage $outbound): RedirectResponse
    {
        // A message already on its way out must not be edited underneath the job.
        if (in_array($outbound->status, [OutboundStatus::Sending, OutboundStatus::Sent], true)) {
            return back()->with('message', 'That message has already been sent.');
        }

        $outbound->update($this->attributes($this->validateDraft($request)));

        return back();
    }

    public function send(Request $request, OutboundMessage $outbound): RedirectResponse
    {
        if ($outbound->status === OutboundStatus::Sent) {
            return back()->with('message', 'Already sent.');
        }

        $outbound->update($this->attributes($this->validateDraft($request)));

        if (($outbound->to_addrs ?? []) === []) {
            return back()->withErrors(['to' => 'Add at least one recipient.']);
        }

        $outbound->update(['status' => OutboundStatus::Queued, 'error' => null]);

        SendMessageJob::dispatch($outbound);

        return $outbound->thread_id === null
            ? redirect()->route('inbox')->with('message', 'Sending — track it in the Outbox until it lands in Sent.')
            : redirect()->route('threads.show', $outbound->thread_id)->with('message', 'Sending — track it in the Outbox until it lands in Sent.');
    }

    public function destroy(OutboundMessage $outbound): RedirectResponse
    {
        if ($outbound->status === OutboundStatus::Sent) {
            return back()->with('message', 'That message has already been sent.');
        }

        $outbound->discard();

        return redirect()->route('inbox');
    }

    /** Stage an upload so the draft can carry it without holding it in the payload. */
    public function attach(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:25600'], // 25 MB, under every provider's ceiling
            'outbound' => ['required', 'integer', 'exists:outbound_messages,id'],
        ]);

        $outbound = OutboundMessage::findOrFail($request->integer('outbound'));
        $file = $request->file('file');

        $path = $file->store('outbound/'.$outbound->id, 'local');

        $outbound->update([
            'attachments' => [
                ...($outbound->attachments ?? []),
                [
                    'path' => $path,
                    'filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size_bytes' => $file->getSize(),
                ],
            ],
        ]);

        return back();
    }

    private function validateDraft(Request $request): array
    {
        return $request->validate([
            'mail_account_id' => ['required', 'integer', 'exists:mail_accounts,id'],
            'type' => ['required', Rule::enum(OutboundType::class)],
            'thread_id' => ['nullable', 'integer', 'exists:threads,id'],
            'in_reply_to_message_id' => ['nullable', 'integer', 'exists:messages,id'],
            'to' => ['array'],
            'to.*.address' => ['required', 'email'],
            'to.*.name' => ['nullable', 'string', 'max:200'],
            'cc' => ['array'],
            'cc.*.address' => ['required', 'email'],
            'cc.*.name' => ['nullable', 'string', 'max:200'],
            'bcc' => ['array'],
            'bcc.*.address' => ['required', 'email'],
            'bcc.*.name' => ['nullable', 'string', 'max:200'],
            'subject' => ['nullable', 'string', 'max:998'], // RFC 5322 line-length ceiling
            'body_html' => ['nullable', 'string'],
        ]);
    }

    private function attributes(array $data): array
    {
        return [
            'mail_account_id' => $data['mail_account_id'],
            'type' => $data['type'],
            'thread_id' => $data['thread_id'] ?? null,
            'in_reply_to_message_id' => $data['in_reply_to_message_id'] ?? null,
            'to_addrs' => $data['to'] ?? [],
            'cc_addrs' => $data['cc'] ?? [],
            'bcc_addrs' => $data['bcc'] ?? [],
            'subject' => $data['subject'] ?? '',
            'body_html' => $data['body_html'] ?? '',
        ];
    }

    /**
     * Reply from the mailbox that received the message, so the conversation stays on
     * the address the other side already knows.
     */
    private function sendingAccount(?int $requested, ?Message $parent): ?MailAccount
    {
        if ($requested !== null) {
            return MailAccount::find($requested);
        }

        return $parent?->mailAccount ?? MailAccount::query()->orderBy('id')->first();
    }

    private function blankDraft(OutboundType $type, MailAccount $account, ?Message $parent): array
    {
        if ($parent === null) {
            return [
                'id' => null,
                'type' => $type->value,
                'mail_account_id' => $account->id,
                'thread_id' => null,
                'in_reply_to_message_id' => null,
                'to' => [], 'cc' => [], 'bcc' => [],
                'subject' => '',
                'body_html' => '',
                'attachments' => [],
                'status' => OutboundStatus::Draft->value,
                'error' => null,
            ];
        }

        $recipients = $this->recipients->for($parent, $type, $account);

        return [
            'id' => null,
            'type' => $type->value,
            'mail_account_id' => $account->id,
            'thread_id' => $parent->thread_id,
            'in_reply_to_message_id' => $parent->id,
            'to' => $recipients['to'],
            'cc' => $recipients['cc'],
            'bcc' => [],
            'subject' => $type === OutboundType::Forward
                ? ReplyHeaders::forwardSubject($parent->subject)
                : ReplyHeaders::replySubject($parent->subject),
            'body_html' => $this->quotes->for($parent, $type),
            'attachments' => [],
            'status' => OutboundStatus::Draft->value,
            'error' => null,
        ];
    }
}

<?php

namespace App\Mail\Support;

use App\Enums\OutboundType;
use App\Mail\Data\Address;
use App\Mail\Data\OutboundDraft;
use App\Models\OutboundMessage;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a stored draft row into the provider-facing OutboundDraft, attaching the
 * RFC threading headers taken from the message being replied to.
 */
class OutboundDraftFactory
{
    public function from(OutboundMessage $outbound): OutboundDraft
    {
        $parent = $outbound->inReplyToMessage;

        $threading = $parent === null
            ? ['in_reply_to' => null, 'references' => []]
            : ReplyHeaders::for($parent);

        return new OutboundDraft(
            type: $outbound->type,
            to: Address::listFromArray($outbound->to_addrs),
            subject: (string) $outbound->subject,
            bodyHtml: (string) $outbound->body_html,
            cc: Address::listFromArray($outbound->cc_addrs),
            bcc: Address::listFromArray($outbound->bcc_addrs),
            attachments: $this->attachments($outbound),
            inReplyTo: $threading['in_reply_to'],
            references: $threading['references'],
            // Gmail files a reply onto the right conversation from threadId, and
            // Graph from the message id it builds the reply against. A thread id is
            // only meaningful inside the mailbox that issued it: replying from a
            // different connected account with the parent's id makes Gmail reject
            // the send, so a cross-account reply relies on the References headers
            // alone — which is all the recipient's client uses anyway.
            providerThreadId: $parent !== null && $parent->mail_account_id === $outbound->mail_account_id
                ? $parent->provider_thread_id
                : null,
            replyToProviderMessageId: $outbound->type === OutboundType::Forward
                ? null
                : $parent?->provider_message_id,
        );
    }

    /**
     * @return list<array{path: string, filename: string, mime_type: ?string}>
     */
    private function attachments(OutboundMessage $outbound): array
    {
        $attachments = [];

        foreach ($outbound->attachments ?? [] as $attachment) {
            $path = $attachment['path'] ?? null;

            // A staged upload can be swept before the draft is sent; skipping is
            // better than failing the whole send on a missing temp file.
            if ($path === null || ! Storage::disk('local')->exists($path)) {
                continue;
            }

            $attachments[] = [
                'path' => Storage::disk('local')->path($path),
                'filename' => $attachment['filename'] ?? basename($path),
                'mime_type' => $attachment['mime_type'] ?? null,
            ];
        }

        return $attachments;
    }
}

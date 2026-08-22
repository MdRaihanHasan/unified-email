<?php

namespace App\Mail\Data;

use App\Enums\OutboundType;

/**
 * A message ready to hand to a provider.
 *
 * $inReplyTo / $references carry the RFC 5322 threading chain. We build these
 * ourselves now — there is no email service doing it for us — with one exception:
 * Graph replies go through createReply, which sets the headers server-side.
 */
final readonly class OutboundDraft
{
    /**
     * @param  list<Address>  $to
     * @param  list<Address>  $cc
     * @param  list<Address>  $bcc
     * @param  list<string>  $references
     * @param  list<array{path: string, filename: string, mime_type: ?string}>  $attachments
     */
    public function __construct(
        public OutboundType $type,
        public array $to,
        public string $subject,
        public string $bodyHtml,
        public array $cc = [],
        public array $bcc = [],
        public array $attachments = [],
        public ?string $inReplyTo = null,
        public array $references = [],
        public ?string $providerThreadId = null,
        public ?string $replyToProviderMessageId = null,
    ) {}
}

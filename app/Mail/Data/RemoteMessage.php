<?php

namespace App\Mail\Data;

use DateTimeImmutable;

/**
 * One message as the provider describes it, normalised across Gmail, IMAP and Graph.
 * Everything a provider adapter knows lands here; nothing above this layer should
 * need to know which provider it came from.
 */
final readonly class RemoteMessage
{
    /**
     * @param  list<Address>  $to
     * @param  list<Address>  $cc
     * @param  list<Address>  $bcc
     * @param  list<Address>  $replyTo
     * @param  list<string>  $references  In-Reply-To chain, oldest first.
     * @param  list<string>  $folderRemoteIds  Usually one entry; several for Gmail labels.
     * @param  list<RemoteAttachment>  $attachments
     */
    public function __construct(
        public string $providerMessageId,
        public ?string $providerThreadId = null,
        public ?string $rfc822MessageId = null,
        public ?string $inReplyTo = null,
        public array $references = [],
        public ?Address $from = null,
        public array $to = [],
        public array $cc = [],
        public array $bcc = [],
        public array $replyTo = [],
        public ?string $subject = null,
        public ?string $snippet = null,
        public ?string $bodyHtml = null,
        public ?string $bodyText = null,
        public ?DateTimeImmutable $sentAt = null,
        public ?DateTimeImmutable $receivedAt = null,
        public bool $isRead = false,
        public bool $isStarred = false,
        public bool $isDraft = false,
        public bool $isAnswered = false,
        public ?int $sizeBytes = null,
        public array $folderRemoteIds = [],
        public array $attachments = [],
        public array $headers = [],
    ) {}

    public function hasAttachments(): bool
    {
        foreach ($this->attachments as $attachment) {
            if (! $attachment->isInline) {
                return true;
            }
        }

        return false;
    }
}

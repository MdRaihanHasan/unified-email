<?php

namespace App\Mail\Providers\Gmail;

use App\Mail\Data\RemoteAttachment;
use App\Mail\Data\RemoteMessage;
use DateTimeImmutable;
use Google\Service\Gmail\Message as GmailMessage;
use Google\Service\Gmail\MessagePart;

/**
 * Turns a Gmail API message into the normalised shape the rest of the app speaks.
 *
 * Two Gmail specifics land here. Flags are labels — a message is unread because it
 * carries UNREAD, not because a boolean says so. And folders are labels too, so a
 * message reports several at once, which is why message_folders is a pivot table.
 */
class MessageParser
{
    /**
     * System labels that are not places mail lives: flags, Gmail's own priority
     * guesses, and the tab categories. Recorded, but never walked as folders.
     */
    public const NON_FOLDER_LABELS = [
        'UNREAD', 'STARRED', 'IMPORTANT', 'CHAT',
        'CATEGORY_PERSONAL', 'CATEGORY_SOCIAL', 'CATEGORY_PROMOTIONS',
        'CATEGORY_UPDATES', 'CATEGORY_FORUMS',
    ];

    public function parse(GmailMessage $message): RemoteMessage
    {
        $labels = $message->getLabelIds() ?? [];
        $payload = $message->getPayload();
        $headers = $this->headers($payload);

        [$html, $text, $attachments] = $payload === null
            ? [null, null, []]
            : $this->walk($payload);

        return new RemoteMessage(
            providerMessageId: $message->getId(),
            providerThreadId: $message->getThreadId(),
            rfc822MessageId: $this->header($headers, 'message-id'),
            inReplyTo: $this->header($headers, 'in-reply-to'),
            references: $this->references($headers),
            from: AddressParser::first($this->header($headers, 'from')),
            to: AddressParser::list($this->header($headers, 'to')),
            cc: AddressParser::list($this->header($headers, 'cc')),
            bcc: AddressParser::list($this->header($headers, 'bcc')),
            replyTo: AddressParser::list($this->header($headers, 'reply-to')),
            subject: $this->decodeHeader($this->header($headers, 'subject')),
            snippet: $this->snippet($message),
            bodyHtml: $html,
            bodyText: $text,
            sentAt: $this->sentAt($headers),
            receivedAt: $this->receivedAt($message),
            // Flags are labels: read means UNREAD is absent, not that a field says so.
            isRead: ! in_array('UNREAD', $labels, true),
            isStarred: in_array('STARRED', $labels, true),
            isDraft: in_array('DRAFT', $labels, true),
            isAnswered: false, // Gmail does not expose \Answered; threading shows it instead.
            sizeBytes: $message->getSizeEstimate() ?: null,
            folderRemoteIds: $this->folderLabels($labels),
            attachments: $attachments,
            headers: $this->keptHeaders($headers),
        );
    }

    /** Flags and category labels are not folders, so they never reach the pivot. */
    public function folderLabels(array $labels): array
    {
        return array_values(array_filter(
            $labels,
            fn (string $label) => ! in_array($label, self::NON_FOLDER_LABELS, true),
        ));
    }

    /** @return array<string, string> lowercased header name => value */
    private function headers(?MessagePart $payload): array
    {
        $headers = [];

        foreach ($payload?->getHeaders() ?? [] as $header) {
            // First occurrence wins: a forged duplicate appended later must not
            // override the real one.
            $name = strtolower($header->getName());
            $headers[$name] ??= $header->getValue();
        }

        return $headers;
    }

    private function header(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? null;

        return blank($value) ? null : $value;
    }

    /**
     * The Gmail API hands headers back verbatim, so a non-ASCII Subject arrives as
     * RFC 2047 encoded-words ("=?UTF-8?B?4Kaq4Kaw...?=") — which is exactly what a
     * Bangla subject line looks like without this. Decoding failures keep the raw
     * value: a garbled header beats a lost one.
     */
    private function decodeHeader(?string $value): ?string
    {
        if ($value === null || ! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false || $decoded === '' ? $value : $decoded;
    }

    /** @return list<string> */
    private function references(array $headers): array
    {
        $raw = $this->header($headers, 'references');

        if ($raw === null) {
            return [];
        }

        preg_match_all('/<[^>]+>/', $raw, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * Walk the MIME tree for the best body parts and every real attachment.
     *
     * @return array{0: ?string, 1: ?string, 2: list<RemoteAttachment>}
     */
    private function walk(MessagePart $part, bool $inAlternative = false): array
    {
        $mime = strtolower((string) $part->getMimeType());
        $filename = (string) $part->getFilename();
        $body = $part->getBody();
        $parts = $part->getParts() ?? [];

        // A part with a filename or an attachmentId is a file, whatever its type —
        // that is how an inline image and an attached PDF are both represented.
        $isAttachment = $filename !== '' || ! blank($body?->getAttachmentId());

        if ($isAttachment && $parts === []) {
            return [null, null, [new RemoteAttachment(
                filename: $filename !== '' ? $filename : 'attachment',
                remoteId: $body?->getAttachmentId(),
                mimeType: $mime !== '' ? $mime : null,
                sizeBytes: $body?->getSize() ?: null,
                isInline: $this->isInline($part),
                contentId: $this->contentId($part),
            )]];
        }

        if ($parts === []) {
            $decoded = $this->decode($body?->getData());

            return match (true) {
                $mime === 'text/html' => [$decoded, null, []],
                str_starts_with($mime, 'text/') => [null, $decoded, []],
                default => [null, null, []],
            };
        }

        $html = null;
        $text = null;
        $attachments = [];
        $alternative = $inAlternative || $mime === 'multipart/alternative';

        foreach ($parts as $child) {
            [$childHtml, $childText, $childAttachments] = $this->walk($child, $alternative);

            // Keep the first of each: in multipart/alternative later parts are
            // richer, but nesting means "first found while descending" is the one
            // that belongs to this message rather than to a quoted one.
            $html ??= $childHtml;
            $text ??= $childText;
            $attachments = [...$attachments, ...$childAttachments];
        }

        return [$html, $text, $attachments];
    }

    private function isInline(MessagePart $part): bool
    {
        foreach ($part->getHeaders() ?? [] as $header) {
            if (strtolower($header->getName()) === 'content-disposition') {
                return str_starts_with(strtolower(trim($header->getValue())), 'inline');
            }
        }

        // No disposition but a Content-ID means the body references it, so it is
        // inline rather than an attachment the reader should see listed.
        return $this->contentId($part) !== null;
    }

    private function contentId(MessagePart $part): ?string
    {
        foreach ($part->getHeaders() ?? [] as $header) {
            if (strtolower($header->getName()) === 'content-id') {
                return trim($header->getValue(), ' <>');
            }
        }

        return null;
    }

    /** Gmail encodes part bodies as base64url, which is not what base64_decode expects. */
    private function decode(?string $data): ?string
    {
        if (blank($data)) {
            return null;
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), false);

        return $decoded === false ? null : $decoded;
    }

    private function snippet(GmailMessage $message): ?string
    {
        $snippet = html_entity_decode((string) $message->getSnippet(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return blank($snippet) ? null : $snippet;
    }

    private function sentAt(array $headers): ?DateTimeImmutable
    {
        $date = $this->header($headers, 'date');

        if ($date === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($date);
        } catch (\Exception) {
            // A malformed Date header is common; internalDate covers us.
            return null;
        }
    }

    private function receivedAt(GmailMessage $message): ?DateTimeImmutable
    {
        $millis = $message->getInternalDate();

        if (blank($millis)) {
            return null;
        }

        return (new DateTimeImmutable)->setTimestamp((int) ((int) $millis / 1000));
    }

    /** Only the headers worth keeping: the full set is large and mostly noise. */
    private function keptHeaders(array $headers): array
    {
        $keep = ['list-id', 'list-unsubscribe', 'precedence', 'auto-submitted', 'return-path'];

        return array_intersect_key($headers, array_flip($keep));
    }
}

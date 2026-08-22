<?php

namespace App\Mail\Support;

use App\Models\Message;

/**
 * Builds the RFC 5322 threading headers for a reply.
 *
 * Getting References wrong is how a reply silently starts a new thread in the
 * recipient's client, so the rules are followed literally (RFC 5322 §3.6.4):
 * References is the parent's References plus the parent's Message-ID, and the
 * chain is trimmed from the *middle* — the root and the most recent ancestors are
 * what threading actually needs.
 */
class ReplyHeaders
{
    /** Keep well clear of the practical header size ceiling. */
    private const MAX_BYTES = 16384;

    public static function for(Message $parent): array
    {
        return [
            'in_reply_to' => $parent->rfc822_message_id,
            'references' => self::trim($parent->replyReferences()),
        ];
    }

    /**
     * @param  list<string>  $references
     * @return list<string>
     */
    public static function trim(array $references): array
    {
        while (count($references) > 3 && strlen(implode(' ', $references)) > self::MAX_BYTES) {
            // Drop the second entry: keep the root for thread identity and keep the
            // tail, which is what clients walk to find the immediate parent.
            array_splice($references, 1, 1);
        }

        return array_values($references);
    }

    /** "Re: " prefixing that does not stack up on an already-prefixed subject. */
    public static function replySubject(?string $subject): string
    {
        $subject = trim((string) $subject);

        if ($subject === '') {
            return 'Re:';
        }

        return preg_match('/^re\s*(\[\d+\])?\s*:/iu', $subject) === 1
            ? $subject
            : 'Re: '.$subject;
    }

    public static function forwardSubject(?string $subject): string
    {
        $subject = trim((string) $subject);

        if ($subject === '') {
            return 'Fwd:';
        }

        return preg_match('/^(fwd?|fw)\s*:/iu', $subject) === 1
            ? $subject
            : 'Fwd: '.$subject;
    }
}

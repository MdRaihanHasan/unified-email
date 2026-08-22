<?php

namespace App\Mail\Support;

use App\Enums\OutboundType;
use App\Mail\Data\Address;
use App\Models\Message;

/**
 * Builds the quoted original that goes under a reply or forward.
 *
 * The original body is re-sanitised on the way in, not trusted because it is
 * already stored: what gets quoted here is about to be sent back out to other
 * people, so it must not carry script or tracking pixels onward.
 */
class QuoteBuilder
{
    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    public function for(Message $parent, OutboundType $type): string
    {
        // Remote images are removed, not merely blocked: forwarding a tracking pixel
        // would fire it for every new recipient.
        $body = $parent->body_html !== null
            ? $this->sanitizer->sanitizeForQuoting($parent->body_html)
            : $this->sanitizer->fromText($parent->body_text);

        return $type === OutboundType::Forward
            ? $this->forwardBlock($parent, $body)
            : $this->replyBlock($parent, $body);
    }

    private function replyBlock(Message $parent, string $body): string
    {
        $when = $parent->sent_at ?? $parent->received_at;
        $who = $this->describe($parent->from_addr);

        $attribution = $when === null
            ? sprintf('%s wrote:', e($who))
            : sprintf('On %s, %s wrote:', e($when->toDayDateTimeString()), e($who));

        return sprintf(
            '<p><br></p><p>%s</p><blockquote type="cite">%s</blockquote>',
            $attribution,
            $body,
        );
    }

    private function forwardBlock(Message $parent, string $body): string
    {
        $rows = [
            'From' => $this->describe($parent->from_addr),
            'Date' => ($parent->sent_at ?? $parent->received_at)?->toDayDateTimeString(),
            'Subject' => $parent->subject,
            'To' => $this->describeList($parent->to_addrs),
        ];

        if ($this->describeList($parent->cc_addrs) !== '') {
            $rows['Cc'] = $this->describeList($parent->cc_addrs);
        }

        $header = '';

        foreach ($rows as $label => $value) {
            if ($value !== null && $value !== '') {
                $header .= sprintf('<div><strong>%s:</strong> %s</div>', $label, e($value));
            }
        }

        return sprintf(
            '<p><br></p><p>---------- Forwarded message ----------</p>%s<p><br></p>%s',
            $header,
            $body,
        );
    }

    private function describe(?array $address): string
    {
        if ($address === null || empty($address['address'])) {
            return '(unknown sender)';
        }

        return empty($address['name'])
            ? $address['address']
            : sprintf('%s <%s>', $address['name'], $address['address']);
    }

    private function describeList(?array $addresses): string
    {
        return implode(', ', array_map(
            fn (Address $address) => (string) $address,
            Address::listFromArray($addresses),
        ));
    }
}

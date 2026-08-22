<?php

namespace App\Mail\Support;

use App\Mail\Data\Address as MailAddress;
use App\Mail\Data\OutboundDraft;
use App\Models\MailAccount;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\File;

/**
 * Builds the RFC 5322 message that goes on the wire.
 *
 * Needed by the Gmail API (which takes a raw MIME blob) and by SMTP. Graph builds
 * its own payload, but still borrows the Message-ID and threading headers from here
 * so all three providers behave the same.
 */
class MimeBuilder
{
    public function build(MailAccount $account, OutboundDraft $draft, ?string $messageId = null): Email
    {
        $email = (new Email)
            ->from(new Address($account->email, $account->display_name ?? ''))
            ->subject($draft->subject)
            ->html($this->withSignature($account, $draft->bodyHtml))
            ->text($this->toPlainText($draft->bodyHtml));

        foreach ($draft->to as $address) {
            $email->addTo($this->address($address));
        }

        foreach ($draft->cc as $address) {
            $email->addCc($this->address($address));
        }

        foreach ($draft->bcc as $address) {
            $email->addBcc($this->address($address));
        }

        foreach ($draft->attachments as $attachment) {
            $email->addPart(new DataPart(
                new File($attachment['path']),
                $attachment['filename'],
                $attachment['mime_type'] ?? null,
            ));
        }

        $headers = $email->getHeaders();

        // Set our own Message-ID rather than letting the transport invent one. We
        // need to know it: the sent copy comes back through normal sync from the
        // Sent folder, and this is what matches it to the message we just sent.
        $headers->addIdHeader('Message-ID', [$this->stripBrackets($messageId ?? $this->generateMessageId($account))]);

        $this->addThreadingHeaders($headers, $draft);

        return $email;
    }

    /** Raw RFC 5322 bytes, for the Gmail API and for anything that wants base64url. */
    public function raw(MailAccount $account, OutboundDraft $draft, ?string $messageId = null): string
    {
        return $this->build($account, $draft, $messageId)->toString();
    }

    public function generateMessageId(MailAccount $account): string
    {
        $domain = str_contains($account->email, '@')
            ? substr($account->email, strpos($account->email, '@') + 1)
            : 'localhost';

        return sprintf('<%s@%s>', bin2hex(random_bytes(16)), $domain);
    }

    private function addThreadingHeaders(Headers $headers, OutboundDraft $draft): void
    {
        if ($draft->inReplyTo !== null) {
            $this->addIdHeader($headers, 'In-Reply-To', [$draft->inReplyTo]);
        }

        if ($draft->references !== []) {
            $this->addIdHeader($headers, 'References', ReplyHeaders::trim($draft->references));
        }
    }

    /**
     * Symfony validates identification headers against the RFC grammar, and
     * Message-IDs in the wild routinely fail it. Refusing to send a reply because
     * the original had an odd Message-ID is the wrong trade: fall back to a plain
     * text header so the value still reaches the recipient's threading logic.
     *
     * @param  list<string>  $ids
     */
    private function addIdHeader(Headers $headers, string $name, array $ids): void
    {
        $stripped = array_map($this->stripBrackets(...), $ids);

        try {
            $headers->addIdHeader($name, $stripped);
        } catch (RfcComplianceException) {
            $headers->addTextHeader($name, implode(' ', array_map(
                fn (string $id) => '<'.$id.'>',
                $stripped,
            )));
        }
    }

    private function stripBrackets(string $id): string
    {
        return trim($id, " \t<>");
    }

    private function address(MailAddress $address): Address
    {
        return new Address($address->address, $address->name ?? '');
    }

    private function withSignature(MailAccount $account, string $html): string
    {
        if (blank($account->signature_html)) {
            return $html;
        }

        return $html.'<br><div class="signature">'.$account->signature_html.'</div>';
    }

    /**
     * A text/plain alternative, so the message is readable in clients that ask for
     * one and scores better with spam filters than HTML alone.
     */
    private function toPlainText(string $html): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|li|tr|h[1-6]|blockquote)>/i', "\n\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}

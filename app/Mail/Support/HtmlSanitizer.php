<?php

namespace App\Mail\Support;

use DOMDocument;
use DOMElement;
use Mews\Purifier\Facades\Purifier;

/**
 * Makes an email body safe to render, and quiet by default.
 *
 * Email HTML is attacker-controlled: anyone can send us a message. So the body is
 * never rendered as received. Two separate jobs happen here:
 *
 *   1. Sanitising — HTMLPurifier against an allowlist, which removes script, event
 *      handlers, javascript: URLs, iframes, forms and CSS that could break out of
 *      the message container.
 *   2. Blocking remote content — every remote image is a tracking pixel until
 *      proven otherwise. Loading one tells the sender the mail was opened, from
 *      what IP, at what time. Sources are parked on a data attribute so the UI can
 *      offer "show images" per message instead of leaking on open.
 */
class HtmlSanitizer
{
    private const CONFIG = [
        'HTML.Allowed' => 'p,br,hr,b,strong,i,em,u,s,strike,sub,sup,'
            .'a[href|title],ul,ol,li,dl,dt,dd,blockquote,pre,code,'
            .'h1,h2,h3,h4,h5,h6,'
            .'img[src|alt|width|height],'
            .'table[border|cellpadding|cellspacing],thead,tbody,tfoot,'
            .'tr,td[colspan|rowspan|align|valign],th[colspan|rowspan|align|valign],'
            .'div,span,font[color|face]',
        'CSS.AllowedProperties' => 'color,background-color,font-weight,font-style,'
            .'font-size,font-family,text-decoration,text-align,'
            .'margin,margin-top,margin-bottom,padding,border,border-collapse,width',
        'HTML.TargetBlank' => true,
        'HTML.Nofollow' => true,
        'AutoFormat.RemoveEmpty' => false,
        // data: is not allowed, because a data: URL can carry markup. cid: is
        // handled by the sentinel below rather than listed here.
        'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true],
    ];

    /**
     * HTMLPurifier only permits URI schemes it has a registered handler for, and it
     * has none for cid:, so listing it in URI.AllowedSchemes still strips inline
     * attachment images. They are parked on a host under .invalid — a TLD reserved
     * by RFC 2606 that can never resolve — and restored afterwards.
     */
    private const CID_SENTINEL = 'https://cid.invalid/';

    /**
     * @return array{html: string, blocked_images: int}
     */
    public function sanitize(?string $html, bool $allowRemoteImages = false): array
    {
        if ($html === null || trim($html) === '') {
            return ['html' => '', 'blocked_images' => 0];
        }

        $clean = $this->restoreContentIds(Purifier::clean($this->parkContentIds($html), self::CONFIG));

        if ($clean === '' || $allowRemoteImages) {
            return ['html' => $clean, 'blocked_images' => 0];
        }

        return $this->blockRemoteImages($clean);
    }

    /**
     * Sanitise a body that is about to be quoted into outgoing mail.
     *
     * Remote images are removed outright rather than parked on a data attribute.
     * Parking is right for reading — the UI can offer to load them — but a quote is
     * about to be mailed to other people, and there is no reason for their copy to
     * carry the original sender's tracker URL at all.
     */
    public function sanitizeForQuoting(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $clean = $this->restoreContentIds(Purifier::clean($this->parkContentIds($html), self::CONFIG));

        return $clean === '' ? '' : $this->stripRemoteImages($clean);
    }

    /** Plain-text fallback for messages that carry no HTML part. */
    public function fromText(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        return '<pre class="email-plain">'.e($text).'</pre>';
    }

    private function parkContentIds(string $html): string
    {
        return preg_replace_callback(
            '/(<img\b[^>]*?\bsrc\s*=\s*["\'])cid:([^"\']+)(["\'])/i',
            fn (array $m) => $m[1].self::CID_SENTINEL.rawurlencode($m[2]).$m[3],
            $html,
        ) ?? $html;
    }

    private function restoreContentIds(string $html): string
    {
        return preg_replace_callback(
            '/'.preg_quote(self::CID_SENTINEL, '/').'([^"\'\s>]+)/i',
            fn (array $m) => 'cid:'.rawurldecode($m[1]),
            $html,
        ) ?? $html;
    }

    /**
     * @return array{html: string, blocked_images: int}
     */
    private function blockRemoteImages(string $html): array
    {
        $blocked = 0;

        $result = $this->rewriteRemoteImages($html, function (DOMElement $image, string $source) use (&$blocked) {
            $image->removeAttribute('src');
            $image->setAttribute('data-blocked-src', $source);
            $blocked++;
        });

        return ['html' => $result, 'blocked_images' => $blocked];
    }

    private function stripRemoteImages(string $html): string
    {
        return $this->rewriteRemoteImages(
            $html,
            fn (DOMElement $image) => $image->parentNode?->removeChild($image),
        );
    }

    /**
     * Walk every remote <img> and hand it to $handle.
     *
     * cid: is skipped throughout: it refers to an attachment on this message rather
     * than a third-party host, so it leaks nothing.
     *
     * @param  callable(DOMElement, string): void  $handle
     */
    private function rewriteRemoteImages(string $html, callable $handle): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // The fragment is already purified; the wrapper just gives DOMDocument a
        // single root and a UTF-8 hint without adding html/body to the output.
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="email-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // Snapshot the list first: removing nodes mutates a live DOMNodeList and
        // silently skips elements mid-iteration.
        $images = iterator_to_array($document->getElementsByTagName('img'));

        foreach ($images as $image) {
            /** @var DOMElement $image */
            $source = $image->getAttribute('src');

            if ($source === '' || str_starts_with(strtolower($source), 'cid:')) {
                continue;
            }

            $handle($image, $source);
        }

        $root = $document->getElementById('email-root');

        return $root === null ? $html : $this->innerHtml($document, $root);
    }

    private function innerHtml(DOMDocument $document, DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return $html;
    }
}

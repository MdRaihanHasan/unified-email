<?php

namespace Tests\Feature;

use App\Mail\Support\HtmlSanitizer;
use Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = app(HtmlSanitizer::class);
    }

    public function test_script_tags_are_removed(): void
    {
        $result = $this->sanitizer->sanitize('<p>Hi</p><script>alert(1)</script>');

        $this->assertStringNotContainsString('script', $result['html']);
        $this->assertStringContainsString('Hi', $result['html']);
    }

    public function test_inline_event_handlers_are_removed(): void
    {
        $result = $this->sanitizer->sanitize('<p onclick="steal()">Click</p><div onmouseover="x()">Hover</div>');

        $this->assertStringNotContainsString('onclick', $result['html']);
        $this->assertStringNotContainsString('onmouseover', $result['html']);
    }

    public function test_javascript_urls_are_removed(): void
    {
        $result = $this->sanitizer->sanitize('<a href="javascript:alert(1)">Tap</a>');

        $this->assertStringNotContainsString('javascript:', $result['html']);
    }

    public function test_iframes_forms_and_objects_are_removed(): void
    {
        $result = $this->sanitizer->sanitize(
            '<iframe src="https://evil.test"></iframe>'
            .'<form action="https://evil.test"><input name="password"></form>'
            .'<object data="x.swf"></object>',
        );

        foreach (['iframe', '<form', '<input', 'object'] as $tag) {
            $this->assertStringNotContainsString($tag, $result['html']);
        }
    }

    public function test_data_urls_are_removed_but_cid_survives(): void
    {
        // data: can carry markup; cid: only points at an attachment on this message.
        $result = $this->sanitizer->sanitize('<img src="data:text/html;base64,PHNjcmlwdD4="><img src="cid:logo">');

        $this->assertStringNotContainsString('data:text/html', $result['html']);
        $this->assertStringContainsString('cid:logo', $result['html']);
    }

    public function test_remote_images_are_blocked_by_default(): void
    {
        // Every remote image is a tracking pixel until proven otherwise: loading one
        // tells the sender the mail was opened, from what IP, at what time.
        $result = $this->sanitizer->sanitize('<p>Hello</p><img src="https://tracker.test/pixel.gif">');

        $this->assertSame(1, $result['blocked_images']);
        $this->assertStringContainsString('data-blocked-src="https://tracker.test/pixel.gif"', $result['html']);
        // The url must not remain in a *loading* attribute. Matching on the bare
        // substring would also hit data-blocked-src, so anchor it to the tag.
        $this->assertDoesNotMatchRegularExpression('/<img[^>]*\ssrc=/i', $result['html']);
    }

    public function test_remote_images_load_when_explicitly_allowed(): void
    {
        $result = $this->sanitizer->sanitize(
            '<img src="https://cdn.test/logo.png">',
            allowRemoteImages: true,
        );

        $this->assertSame(0, $result['blocked_images']);
        $this->assertStringContainsString('src="https://cdn.test/logo.png"', $result['html']);
    }

    public function test_inline_attachment_images_are_never_blocked(): void
    {
        $result = $this->sanitizer->sanitize('<img src="cid:signature-logo">');

        $this->assertSame(0, $result['blocked_images'], 'cid: is our own attachment, so it leaks nothing');
        $this->assertStringContainsString('cid:signature-logo', $result['html']);
    }

    public function test_several_remote_images_are_all_counted(): void
    {
        $result = $this->sanitizer->sanitize(
            '<img src="https://a.test/1.gif"><img src="cid:inline"><img src="https://b.test/2.gif">',
        );

        $this->assertSame(2, $result['blocked_images'], 'the cid: image is not remote');
        $this->assertStringContainsString('cid:inline', $result['html']);
    }

    public function test_formatting_markup_survives(): void
    {
        $html = '<p><strong>Bold</strong> and <em>italic</em></p>'
            .'<ul><li>One</li></ul>'
            .'<table><tr><td>Cell</td></tr></table>'
            .'<blockquote>Quoted</blockquote>';

        $result = $this->sanitizer->sanitize($html);

        foreach (['<strong>', '<em>', '<li>', '<td>', '<blockquote>'] as $tag) {
            $this->assertStringContainsString($tag, $result['html']);
        }
    }

    public function test_links_are_marked_safe_for_new_tabs(): void
    {
        $result = $this->sanitizer->sanitize('<a href="https://example.test">Link</a>');

        $this->assertStringContainsString('target="_blank"', $result['html']);
        $this->assertStringContainsString('noreferrer', $result['html']);
    }

    public function test_empty_and_null_bodies_are_handled(): void
    {
        foreach ([null, '', '   '] as $input) {
            $result = $this->sanitizer->sanitize($input);
            $this->assertSame('', $result['html']);
            $this->assertSame(0, $result['blocked_images']);
        }
    }

    public function test_a_quoted_body_drops_remote_images_entirely(): void
    {
        // Blocking is right for reading, but a quote is about to be mailed onward and
        // there is no reason for the recipients' copy to carry the sender's tracker.
        $html = $this->sanitizer->sanitizeForQuoting(
            '<p>Hi</p><img src="https://tracker.test/p.gif"><img src="cid:logo">',
        );

        $this->assertStringNotContainsString('tracker.test', $html);
        $this->assertStringNotContainsString('data-blocked-src', $html);
        // cid images used to be kept here, but MimeBuilder never re-attaches the
        // parts they reference, so keeping the tag mailed broken images onward.
        $this->assertStringNotContainsString('cid:logo', $html);
        $this->assertStringContainsString('Hi', $html);
    }

    public function test_quoting_strips_every_remote_image_not_just_the_first(): void
    {
        // Removing nodes mutates a live DOMNodeList, which silently skips elements
        // when iterated directly.
        $html = $this->sanitizer->sanitizeForQuoting(
            '<img src="https://a.test/1.gif"><img src="https://b.test/2.gif"><img src="https://c.test/3.gif">',
        );

        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_quoting_strips_cid_images_because_the_parts_are_not_reattached(): void
    {
        // MimeBuilder does not attach the parts a cid: reference points at, so
        // leaving the tag in would mail broken images to the recipient.
        $html = $this->sanitizer->sanitizeForQuoting(
            '<p>Regards</p><img src="cid:logo@signature" alt="logo">',
        );

        $this->assertStringNotContainsString('cid:', $html);
        $this->assertStringContainsString('Regards', $html);
    }

    public function test_quoting_still_removes_scripts(): void
    {
        $html = $this->sanitizer->sanitizeForQuoting('<p>Hi</p><script>alert(1)</script>');

        $this->assertStringNotContainsString('script', $html);
    }

    public function test_plain_text_bodies_are_escaped_not_rendered(): void
    {
        $html = $this->sanitizer->fromText("Hello <script>alert(1)</script>\nSecond line");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('email-plain', $html);
    }

    public function test_unicode_subjects_and_bodies_survive_the_dom_round_trip(): void
    {
        // The image blocker runs the body through DOMDocument, which mangles UTF-8
        // without an explicit encoding hint.
        $result = $this->sanitizer->sanitize('<p>চালান ৪২ — ধন্যবাদ</p><img src="https://t.test/p.gif">');

        $this->assertStringContainsString('চালান ৪২', $result['html']);
        $this->assertStringContainsString('ধন্যবাদ', $result['html']);
    }

    public function test_inline_styles_survive_with_safe_properties(): void
    {
        // Real email layout IS inline style; stripping the attribute wholesale is
        // why every newsletter used to collapse into bare stacked text.
        $result = $this->sanitizer->sanitize('<p style="color:#336699; font-size:14px;">Styled</p>');

        $this->assertStringContainsString('style=', $result['html']);
        $this->assertStringContainsString('color', $result['html']);
        $this->assertStringContainsString('font-size', $result['html']);
    }

    public function test_url_bearing_and_unlisted_css_is_stripped(): void
    {
        // background-image would fetch a remote URL the <img> blocker never sees —
        // a tracking pixel with no consent switch — and position could pull content
        // out of the message container.
        $result = $this->sanitizer->sanitize(
            '<div style="background-image:url(https://tracker.test/p.gif); position:fixed; color:red;">x</div>',
        );

        $this->assertStringNotContainsString('background-image', $result['html']);
        $this->assertStringNotContainsString('tracker.test', $result['html']);
        $this->assertStringNotContainsString('position', $result['html']);
        $this->assertStringContainsString('color', $result['html']);
    }
}

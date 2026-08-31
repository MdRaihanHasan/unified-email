<?php

namespace Tests\Unit;

use App\Mail\Providers\Gmail\MessageParser;
use Google\Service\Gmail\Message as GmailMessage;
use PHPUnit\Framework\TestCase;

class GmailMessageParserTest extends TestCase
{
    private MessageParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new MessageParser;
    }

    /** Gmail encodes part bodies as base64url, not base64. */
    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function headers(array $pairs): array
    {
        return array_map(fn ($name, $value) => ['name' => $name, 'value' => $value],
            array_keys($pairs), array_values($pairs));
    }

    /**
     * Merge fixture overrides.
     *
     * array_replace_recursive cannot shrink a list — overriding labelIds with
     * ['INBOX'] would leave the base's UNREAD in place at index 1 — so a list in the
     * override replaces the base outright.
     */
    private function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = is_array($value) && is_array($base[$key] ?? null) && ! array_is_list($value)
                ? $this->merge($base[$key], $value)
                : $value;
        }

        return $base;
    }

    private function message(array $overrides = []): GmailMessage
    {
        return new GmailMessage($this->merge([
            'id' => 'gmail-1',
            'threadId' => 'thread-1',
            'labelIds' => ['INBOX', 'UNREAD'],
            'snippet' => 'Could we move to net-30?',
            'internalDate' => '1787356800000', // 2026-08-22 00:00 UTC
            'sizeEstimate' => 4821,
            'payload' => [
                'mimeType' => 'text/plain',
                'headers' => $this->headers([
                    'From' => 'Anna Bergström <anna@client.test>',
                    'To' => 'me@company.com',
                    'Subject' => 'Invoice 2418',
                    'Message-ID' => '<root@client.test>',
                    'Date' => 'Fri, 21 Aug 2026 20:44:00 +0000',
                ]),
                'body' => ['data' => $this->encode('Could we move to net-30?'), 'size' => 24],
            ],
        ], $overrides));
    }

    public function test_headers_become_addresses_and_a_subject(): void
    {
        $parsed = $this->parser->parse($this->message());

        $this->assertSame('gmail-1', $parsed->providerMessageId);
        $this->assertSame('thread-1', $parsed->providerThreadId);
        $this->assertSame('<root@client.test>', $parsed->rfc822MessageId);
        $this->assertSame('Invoice 2418', $parsed->subject);
        $this->assertSame('anna@client.test', $parsed->from->address);
        $this->assertSame('Anna Bergström', $parsed->from->name);
        $this->assertSame(['me@company.com'], array_map(fn ($a) => $a->address, $parsed->to));
    }

    public function test_flags_are_read_from_labels(): void
    {
        // Gmail has no read boolean: a message is unread because it carries UNREAD.
        $unread = $this->parser->parse($this->message(['labelIds' => ['INBOX', 'UNREAD', 'STARRED']]));
        $this->assertFalse($unread->isRead);
        $this->assertTrue($unread->isStarred);

        $read = $this->parser->parse($this->message(['labelIds' => ['INBOX']]));
        $this->assertTrue($read->isRead);
        $this->assertFalse($read->isStarred);
    }

    public function test_a_draft_label_is_recognised(): void
    {
        $parsed = $this->parser->parse($this->message(['labelIds' => ['DRAFT']]));

        $this->assertTrue($parsed->isDraft);
    }

    public function test_flags_and_category_labels_are_not_folders(): void
    {
        // Filing a message under UNREAD or CATEGORY_PROMOTIONS would make the folder
        // pivot meaningless and re-cover mail INBOX already gave us.
        $parsed = $this->parser->parse($this->message([
            'labelIds' => ['INBOX', 'UNREAD', 'STARRED', 'IMPORTANT', 'CATEGORY_PROMOTIONS', 'Label_7'],
        ]));

        $this->assertSame(['INBOX', 'Label_7'], array_values($parsed->folderRemoteIds));
    }

    public function test_a_message_reports_every_label_it_lives_in(): void
    {
        // The reason message_folders is a pivot: one Gmail message is legitimately
        // in the inbox and two user labels at once.
        $parsed = $this->parser->parse($this->message(['labelIds' => ['INBOX', 'Label_1', 'Label_2']]));

        $this->assertCount(3, $parsed->folderRemoteIds);
    }

    public function test_a_plain_text_body_is_decoded_from_base64url(): void
    {
        $parsed = $this->parser->parse($this->message());

        $this->assertSame('Could we move to net-30?', $parsed->bodyText);
        $this->assertNull($parsed->bodyHtml);
    }

    public function test_base64url_specific_characters_decode(): void
    {
        // "+" and "/" become "-" and "_", which base64_decode does not understand.
        $original = "Terms? 100% — see §4 \xF0\x9F\x93\x8E";

        $parsed = $this->parser->parse($this->message([
            'payload' => ['body' => ['data' => $this->encode($original)]],
        ]));

        $this->assertSame($original, $parsed->bodyText);
    }

    public function test_multipart_alternative_yields_both_bodies(): void
    {
        $parsed = $this->parser->parse($this->message([
            'payload' => [
                'mimeType' => 'multipart/alternative',
                'body' => ['data' => null],
                'parts' => [
                    ['mimeType' => 'text/plain', 'body' => ['data' => $this->encode('Plain version')]],
                    ['mimeType' => 'text/html', 'body' => ['data' => $this->encode('<p>Rich version</p>')]],
                ],
            ],
        ]));

        $this->assertSame('Plain version', $parsed->bodyText);
        $this->assertSame('<p>Rich version</p>', $parsed->bodyHtml);
    }

    public function test_a_nested_mixed_and_alternative_tree_is_walked(): void
    {
        $parsed = $this->parser->parse($this->message([
            'payload' => [
                'mimeType' => 'multipart/mixed',
                'body' => ['data' => null],
                'parts' => [
                    [
                        'mimeType' => 'multipart/alternative',
                        'parts' => [
                            ['mimeType' => 'text/plain', 'body' => ['data' => $this->encode('Text')]],
                            ['mimeType' => 'text/html', 'body' => ['data' => $this->encode('<p>Html</p>')]],
                        ],
                    ],
                    [
                        'mimeType' => 'application/pdf',
                        'filename' => 'statement-2418.pdf',
                        'body' => ['attachmentId' => 'att-1', 'size' => 184320],
                    ],
                ],
            ],
        ]));

        $this->assertSame('Text', $parsed->bodyText);
        $this->assertSame('<p>Html</p>', $parsed->bodyHtml);
        $this->assertCount(1, $parsed->attachments);
        $this->assertSame('statement-2418.pdf', $parsed->attachments[0]->filename);
        $this->assertSame('att-1', $parsed->attachments[0]->remoteId);
        $this->assertSame(184320, $parsed->attachments[0]->sizeBytes);
        $this->assertFalse($parsed->attachments[0]->isInline);
        $this->assertTrue($parsed->hasAttachments());
    }

    public function test_an_inline_image_is_marked_inline_and_does_not_count(): void
    {
        // A signature logo is not an attachment the reader should see listed.
        $parsed = $this->parser->parse($this->message([
            'payload' => [
                'mimeType' => 'multipart/related',
                'body' => ['data' => null],
                'parts' => [
                    ['mimeType' => 'text/html', 'body' => ['data' => $this->encode('<p>Hi <img src="cid:logo"></p>')]],
                    [
                        'mimeType' => 'image/png',
                        'filename' => 'logo.png',
                        'headers' => $this->headers(['Content-ID' => '<logo>', 'Content-Disposition' => 'inline']),
                        'body' => ['attachmentId' => 'att-logo', 'size' => 512],
                    ],
                ],
            ],
        ]));

        $this->assertCount(1, $parsed->attachments);
        $this->assertTrue($parsed->attachments[0]->isInline);
        $this->assertSame('logo', $parsed->attachments[0]->contentId);
        $this->assertFalse($parsed->hasAttachments(), 'an inline part is not an attachment');
    }

    public function test_a_content_id_alone_implies_inline(): void
    {
        $parsed = $this->parser->parse($this->message([
            'payload' => [
                'mimeType' => 'multipart/related',
                'parts' => [[
                    'mimeType' => 'image/gif',
                    'filename' => 'spacer.gif',
                    'headers' => $this->headers(['Content-ID' => '<spacer>']),
                    'body' => ['attachmentId' => 'att-2'],
                ]],
            ],
        ]));

        $this->assertTrue($parsed->attachments[0]->isInline);
    }

    public function test_the_references_chain_is_parsed_into_ids(): void
    {
        $parsed = $this->parser->parse($this->message([
            'payload' => [
                'headers' => $this->headers([
                    'From' => 'anna@client.test',
                    'In-Reply-To' => '<second@client.test>',
                    'References' => "<root@client.test>\r\n <second@client.test>",
                ]),
            ],
        ]));

        $this->assertSame('<second@client.test>', $parsed->inReplyTo);
        $this->assertSame(['<root@client.test>', '<second@client.test>'], $parsed->references);
    }

    public function test_timestamps_come_from_internal_date_and_the_date_header(): void
    {
        $parsed = $this->parser->parse($this->message());

        $this->assertSame('2026-08-22', $parsed->receivedAt->format('Y-m-d'));
        $this->assertSame('2026-08-21 20:44', $parsed->sentAt->format('Y-m-d H:i'));
    }

    public function test_a_malformed_date_header_does_not_lose_the_message(): void
    {
        $parsed = $this->parser->parse($this->message([
            'payload' => ['headers' => $this->headers(['From' => 'a@x.test', 'Date' => 'not a date'])],
        ]));

        $this->assertNull($parsed->sentAt);
        $this->assertNotNull($parsed->receivedAt, 'internalDate still covers us');
    }

    public function test_the_snippet_is_html_decoded(): void
    {
        $parsed = $this->parser->parse($this->message(['snippet' => 'Terms &amp; conditions &gt; see &quot;4&quot;']));

        $this->assertSame('Terms & conditions > see "4"', $parsed->snippet);
    }

    public function test_a_duplicate_header_does_not_override_the_first(): void
    {
        // A forged Subject appended after the real one must not win.
        $parsed = $this->parser->parse($this->message([
            'payload' => [
                'headers' => [
                    ['name' => 'From', 'value' => 'real@client.test'],
                    ['name' => 'Subject', 'value' => 'Real subject'],
                    ['name' => 'Subject', 'value' => 'Forged subject'],
                    ['name' => 'From', 'value' => 'forged@evil.test'],
                ],
            ],
        ]));

        $this->assertSame('Real subject', $parsed->subject);
        $this->assertSame('real@client.test', $parsed->from->address);
    }

    public function test_a_message_with_no_payload_still_parses(): void
    {
        $parsed = $this->parser->parse(new GmailMessage([
            'id' => 'bare', 'threadId' => 't', 'labelIds' => ['INBOX'],
        ]));

        $this->assertSame('bare', $parsed->providerMessageId);
        $this->assertNull($parsed->from);
        $this->assertNull($parsed->bodyHtml);
        $this->assertSame([], $parsed->attachments);
    }

    public function test_only_useful_headers_are_kept(): void
    {
        $parsed = $this->parser->parse($this->message([
            'payload' => [
                'headers' => $this->headers([
                    'From' => 'a@x.test',
                    'List-Id' => '<list.example.test>',
                    'X-Some-Vendor-Trace' => 'nothing we need',
                ]),
            ],
        ]));

        $this->assertArrayHasKey('list-id', $parsed->headers);
        $this->assertArrayNotHasKey('x-some-vendor-trace', $parsed->headers);
    }

    public function test_an_rfc2047_encoded_subject_decodes(): void
    {
        $subject = '=?UTF-8?B?'.base64_encode('চালান ৪২ — ধন্যবাদ').'?=';

        $remote = $this->parser->parse($this->message([
            'payload' => ['headers' => $this->headers(['Subject' => $subject])],
        ]));

        $this->assertSame('চালান ৪২ — ধন্যবাদ', $remote->subject);
    }
}

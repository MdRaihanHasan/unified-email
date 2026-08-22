<?php

namespace Tests\Feature;

use App\Enums\OutboundType;
use App\Mail\Data\Address;
use App\Mail\Data\OutboundDraft;
use App\Mail\Support\MimeBuilder;
use App\Models\MailAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MimeBuilderTest extends TestCase
{
    use RefreshDatabase;

    private MimeBuilder $builder;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = app(MimeBuilder::class);
        $this->account = MailAccount::factory()->gmailApi()->create([
            'email' => 'me@company.com',
            'display_name' => 'Me',
        ]);
    }

    private function draft(array $overrides = []): OutboundDraft
    {
        return new OutboundDraft(...[
            'type' => OutboundType::New,
            'to' => [new Address('anna@client.test', 'Anna')],
            'subject' => 'Invoice 2418',
            'bodyHtml' => '<p>Hello <strong>Anna</strong></p>',
            ...$overrides,
        ]);
    }

    public function test_the_envelope_carries_sender_recipients_and_subject(): void
    {
        $raw = $this->builder->raw($this->account, $this->draft([
            'cc' => [new Address('bob@client.test')],
            'bcc' => [new Address('archive@company.com')],
        ]));

        $this->assertStringContainsString('From: Me <me@company.com>', $raw);
        $this->assertStringContainsString('anna@client.test', $raw);
        $this->assertStringContainsString('bob@client.test', $raw);
        $this->assertStringContainsString('Invoice 2418', $raw);
    }

    public function test_a_message_id_is_always_set(): void
    {
        // We generate it rather than letting the transport invent one, because the
        // sent copy comes back through sync and this is what matches it.
        $raw = $this->builder->raw($this->account, $this->draft());

        $this->assertMatchesRegularExpression('/Message-ID: <[^>]+@company\.com>/i', $raw);
    }

    public function test_threading_headers_are_written_for_a_reply(): void
    {
        $raw = $this->builder->raw($this->account, $this->draft([
            'type' => OutboundType::Reply,
            'inReplyTo' => '<root@client.test>',
            'references' => ['<root@client.test>', '<second@client.test>'],
        ]));

        $this->assertStringContainsString('In-Reply-To: <root@client.test>', $raw);
        $this->assertStringContainsString('root@client.test', $raw);
        $this->assertStringContainsString('second@client.test', $raw);
    }

    public function test_a_non_compliant_parent_message_id_still_produces_a_sendable_reply(): void
    {
        // Message-IDs in the wild routinely fail the RFC grammar. Refusing to send a
        // reply over it would be the wrong trade, so the builder falls back to a
        // plain text header rather than throwing.
        $raw = $this->builder->raw($this->account, $this->draft([
            'type' => OutboundType::Reply,
            'inReplyTo' => '<not a valid id at all>',
            'references' => ['<not a valid id at all>'],
        ]));

        $this->assertStringContainsString('In-Reply-To:', $raw);
        $this->assertStringContainsString('not a valid id at all', $raw);
    }

    public function test_angle_brackets_are_not_doubled_up(): void
    {
        $raw = $this->builder->raw($this->account, $this->draft([
            'inReplyTo' => '<root@client.test>',
        ]));

        $this->assertStringNotContainsString('<<', $raw);
        $this->assertStringNotContainsString('>>', $raw);
    }

    public function test_a_plain_text_alternative_is_included(): void
    {
        $email = $this->builder->build($this->account, $this->draft([
            'bodyHtml' => '<p>First line</p><p>Second line</p><script>alert(1)</script>',
        ]));

        $text = $email->getTextBody();

        $this->assertStringContainsString('First line', $text);
        $this->assertStringContainsString('Second line', $text);
        $this->assertStringNotContainsString('alert(1)', $text, 'script contents are not readable text');
        $this->assertStringNotContainsString('<p>', $text);
    }

    public function test_a_signature_is_appended_when_the_account_has_one(): void
    {
        $this->account->update(['signature_html' => '<p>— Me, Bixcel</p>']);

        $email = $this->builder->build($this->account, $this->draft());

        $this->assertStringContainsString('Bixcel', $email->getHtmlBody());
    }

    public function test_no_signature_markup_is_added_when_there_is_none(): void
    {
        $email = $this->builder->build($this->account, $this->draft());

        $this->assertStringNotContainsString('signature', $email->getHtmlBody());
    }

    public function test_attachments_are_included(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'attach');
        file_put_contents($path, 'pdf bytes');

        $raw = $this->builder->raw($this->account, $this->draft([
            'attachments' => [['path' => $path, 'filename' => 'statement.pdf', 'mime_type' => 'application/pdf']],
        ]));

        $this->assertStringContainsString('statement.pdf', $raw);
        $this->assertStringContainsString('application/pdf', $raw);

        unlink($path);
    }

    public function test_unicode_subjects_and_bodies_survive_encoding(): void
    {
        $email = $this->builder->build($this->account, $this->draft([
            'subject' => 'চালান ৪২',
            'bodyHtml' => '<p>ধন্যবাদ</p>',
        ]));

        $this->assertSame('চালান ৪২', $email->getSubject());
        $this->assertStringContainsString('ধন্যবাদ', $email->getHtmlBody());
        $this->assertStringContainsString('ধন্যবাদ', $email->getTextBody());
    }

    public function test_each_generated_message_id_is_unique(): void
    {
        $first = $this->builder->generateMessageId($this->account);
        $second = $this->builder->generateMessageId($this->account);

        $this->assertNotSame($first, $second);
        $this->assertStringEndsWith('@company.com>', $first);
    }
}

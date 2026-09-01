<?php

namespace Tests\Feature;

use App\Mail\Data\Address;
use App\Mail\Data\RemoteAttachment;
use App\Mail\Data\RemoteMessage;
use App\Mail\Support\MessageWriter;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UsesFakeProvider;
use Tests\TestCase;

class AttachmentDownloadTest extends TestCase
{
    use RefreshDatabase, UsesFakeProvider;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->fakeProvider();
        $this->account = MailAccount::factory()->gmailApi()->create();
        $this->actingAs(User::factory()->create());
    }

    private function storeMessage(array $attachments, array $overrides = []): Message
    {
        return app(MessageWriter::class)->store($this->account, new RemoteMessage(...[
            'providerMessageId' => 'm1',
            'rfc822MessageId' => '<m1@example.com>',
            'from' => new Address('sender@example.com'),
            'subject' => 'With attachment',
            'receivedAt' => new \DateTimeImmutable('2026-08-01 09:00:00'),
            'attachments' => $attachments,
            ...$overrides,
        ]));
    }

    public function test_a_document_downloads_via_the_provider_and_is_served_as_an_opaque_file(): void
    {
        $message = $this->storeMessage([new RemoteAttachment(
            filename: 'invoice.pdf', remoteId: 'att-1', mimeType: 'application/pdf',
        )]);
        $attachment = $message->attachments->sole();

        $response = $this->get("/messages/{$message->id}/attachments/{$attachment->id}");

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('invoice.pdf', $response->headers->get('Content-Disposition'));

        $attachment->refresh();
        $this->assertNotNull($attachment->disk_path);
        $this->assertSame('fake-bytes-of-att-1', Storage::disk('local')->get($attachment->disk_path));
    }

    public function test_the_second_request_serves_from_disk_without_touching_the_provider(): void
    {
        $message = $this->storeMessage([new RemoteAttachment(filename: 'a.pdf', remoteId: 'att-1')]);
        $attachment = $message->attachments->sole();

        $this->get("/messages/{$message->id}/attachments/{$attachment->id}")->assertOk();
        $this->get("/messages/{$message->id}/attachments/{$attachment->id}")->assertOk();

        $this->assertCount(1, $this->provider->downloadedAttachments);
    }

    public function test_a_safelisted_image_renders_inline_with_its_own_mime(): void
    {
        $message = $this->storeMessage([new RemoteAttachment(
            filename: 'logo.png', remoteId: 'att-img', mimeType: 'image/png',
            isInline: true, contentId: 'logo@sig',
        )]);
        $attachment = $message->attachments->sole();

        $response = $this->get("/messages/{$message->id}/attachments/{$attachment->id}");

        $response->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_an_attachment_of_a_different_message_is_not_reachable(): void
    {
        $message = $this->storeMessage([new RemoteAttachment(filename: 'a.pdf', remoteId: 'att-1')]);
        $other = $this->storeMessage(
            [new RemoteAttachment(filename: 'b.pdf', remoteId: 'att-2')],
            ['providerMessageId' => 'm2', 'rfc822MessageId' => '<m2@example.com>'],
        );

        $this->get("/messages/{$message->id}/attachments/{$other->attachments->sole()->id}")
            ->assertNotFound();
    }

    public function test_guests_cannot_fetch_attachments(): void
    {
        $message = $this->storeMessage([new RemoteAttachment(filename: 'a.pdf', remoteId: 'att-1')]);
        $attachment = $message->attachments->sole();

        auth()->logout();

        $this->get("/messages/{$message->id}/attachments/{$attachment->id}")
            ->assertRedirect('/login');
    }

    public function test_cid_references_in_an_open_thread_rewrite_to_the_attachment_endpoint(): void
    {
        $message = $this->storeMessage(
            [new RemoteAttachment(
                filename: 'logo.png', remoteId: 'att-img', mimeType: 'image/png',
                isInline: true, contentId: 'logo@sig',
            )],
            ['bodyHtml' => '<p>Hi</p><img src="cid:logo@sig">'],
        );
        $attachment = $message->attachments->sole();

        $response = $this->get("/inbox?thread={$message->thread_id}");

        $html = $response->inertiaPage()['props']['open']['messages'][0]['body_html'];
        $this->assertStringNotContainsString('cid:', $html);
        $this->assertStringContainsString("/messages/{$message->id}/attachments/{$attachment->id}", $html);
    }
}

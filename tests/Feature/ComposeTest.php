<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\OutboundStatus;
use App\Enums\OutboundType;
use App\Jobs\SendMessageJob;
use App\Jobs\SyncAccountJob;
use App\Mail\Exceptions\AuthenticationFailedException;
use App\Mail\Support\MimeBuilder;
use App\Mail\Support\OutboundDraftFactory;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\OutboundMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\UsesFakeProvider;
use Tests\TestCase;

class ComposeTest extends TestCase
{
    use RefreshDatabase, UsesFakeProvider;

    private User $user;

    private MailAccount $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeProvider();
        $this->user = User::factory()->create();
        $this->workspace = MailAccount::factory()->gmailApi()->create([
            'email' => 'me@company.com',
            'display_name' => 'Me',
        ]);
    }

    private function incoming(array $overrides = []): Message
    {
        return Message::factory()->for($this->workspace, 'mailAccount')->create([
            'provider_message_id' => 'parent-1',
            'provider_thread_id' => 'gmail-thread-9',
            'rfc822_message_id' => '<root@client.test>',
            'references_ids' => ['<older@client.test>'],
            'from_addr' => ['address' => 'anna@client.test', 'name' => 'Anna'],
            'to_addrs' => [['address' => 'me@company.com', 'name' => null]],
            'subject' => 'Invoice 2418',
            'body_html' => '<p>Please review.</p><img src="https://tracker.test/p.gif">',
            ...$overrides,
        ]);
    }

    private function draftPayload(array $overrides = []): array
    {
        return [
            'mail_account_id' => $this->workspace->id,
            'type' => OutboundType::New->value,
            'to' => [['address' => 'anna@client.test', 'name' => 'Anna']],
            'subject' => 'Hello',
            'body_html' => '<p>Hi Anna</p>',
            ...$overrides,
        ];
    }

    // ---- prefill ----------------------------------------------------------

    public function test_a_reply_is_prefilled_with_sender_subject_and_quote(): void
    {
        $parent = $this->incoming();

        $this->actingAs($this->user)
            ->get("/compose?type=reply&message={$parent->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Compose/Edit')
                ->where('draft.to.0.address', 'anna@client.test')
                ->where('draft.subject', 'Re: Invoice 2418')
                ->where('draft.in_reply_to_message_id', $parent->id)
                ->where('draft.body_html', fn (string $html) => str_contains($html, 'Please review.')
                    && str_contains($html, 'blockquote')));
    }

    public function test_a_quoted_original_does_not_carry_the_senders_tracking_pixel(): void
    {
        // The quote is about to be mailed to other people; firing the pixel for each
        // of them would be worse than firing it once for us.
        $parent = $this->incoming();

        $this->actingAs($this->user)
            ->get("/compose?type=reply&message={$parent->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('draft.body_html', fn (string $html) => ! str_contains($html, 'tracker.test')
                    && ! str_contains($html, '<img')));
    }

    public function test_a_forward_starts_with_no_recipients_and_a_header_block(): void
    {
        $parent = $this->incoming();

        $this->actingAs($this->user)
            ->get("/compose?type=forward&message={$parent->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('draft.to', [])
                ->where('draft.subject', 'Fwd: Invoice 2418')
                ->where('draft.body_html', fn (string $html) => str_contains($html, 'Forwarded message')
                    && str_contains($html, 'anna@client.test')));
    }

    public function test_a_reply_defaults_to_the_mailbox_that_received_the_message(): void
    {
        // Replying from the other account would surface an address the other side
        // does not know.
        $other = MailAccount::factory()->create(['email' => 'me@outlook.com']);
        $parent = Message::factory()->for($other, 'mailAccount')->create([
            'from_addr' => ['address' => 'anna@client.test', 'name' => 'Anna'],
        ]);

        $this->actingAs($this->user)
            ->get("/compose?type=reply&message={$parent->id}")
            ->assertInertia(fn (Assert $page) => $page->where('draft.mail_account_id', $other->id));
    }

    public function test_composing_with_no_mailbox_connected_sends_you_to_settings(): void
    {
        MailAccount::query()->delete();

        $this->actingAs($this->user)->get('/compose')->assertRedirect(route('accounts'));
    }

    public function test_replying_to_a_message_that_no_longer_exists_is_handled(): void
    {
        $this->actingAs($this->user)
            ->from('/inbox')
            ->get('/compose?type=reply&message=99999')
            ->assertSessionHasErrors('message');
    }

    // ---- draft persistence ------------------------------------------------

    public function test_a_draft_is_saved_and_can_be_reopened(): void
    {
        $this->actingAs($this->user)->post('/compose', $this->draftPayload())->assertRedirect();

        $draft = OutboundMessage::sole();
        $this->assertSame(OutboundStatus::Draft, $draft->status);
        $this->assertSame('Hello', $draft->subject);

        $this->actingAs($this->user)->get("/compose/{$draft->id}")
            ->assertInertia(fn (Assert $page) => $page->where('draft.subject', 'Hello'));
    }

    public function test_autosave_updates_the_existing_draft(): void
    {
        $this->actingAs($this->user)->post('/compose', $this->draftPayload());
        $draft = OutboundMessage::sole();

        $this->actingAs($this->user)
            ->patch("/compose/{$draft->id}", $this->draftPayload(['subject' => 'Hello again']));

        $this->assertSame(1, OutboundMessage::count());
        $this->assertSame('Hello again', $draft->fresh()->subject);
    }

    public function test_a_malformed_recipient_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->post('/compose', $this->draftPayload(['to' => [['address' => 'not-an-email']]]))
            ->assertSessionHasErrors('to.0.address');
    }

    public function test_a_sent_message_cannot_be_edited(): void
    {
        $draft = OutboundMessage::create([
            'mail_account_id' => $this->workspace->id,
            'type' => OutboundType::New,
            'status' => OutboundStatus::Sent,
            'subject' => 'Already gone',
        ]);

        $this->actingAs($this->user)
            ->patch("/compose/{$draft->id}", $this->draftPayload(['subject' => 'Rewritten']))
            ->assertSessionHas('message');

        $this->assertSame('Already gone', $draft->fresh()->subject);
    }

    public function test_a_draft_can_be_discarded_and_its_uploads_removed(): void
    {
        Storage::fake('local');

        $this->actingAs($this->user)->post('/compose', $this->draftPayload());
        $draft = OutboundMessage::sole();

        $this->actingAs($this->user)->post('/compose/attach', [
            'outbound' => $draft->id,
            'file' => UploadedFile::fake()->create('statement.pdf', 12),
        ]);

        $path = $draft->fresh()->attachments[0]['path'];
        Storage::disk('local')->assertExists($path);

        $this->actingAs($this->user)->delete("/compose/{$draft->id}")->assertRedirect(route('inbox'));

        $this->assertSame(0, OutboundMessage::count());
        Storage::disk('local')->assertMissing($path);
    }

    // ---- attachments ------------------------------------------------------

    public function test_an_upload_is_staged_against_the_draft(): void
    {
        Storage::fake('local');

        $this->actingAs($this->user)->post('/compose', $this->draftPayload());
        $draft = OutboundMessage::sole();

        $this->actingAs($this->user)->post('/compose/attach', [
            'outbound' => $draft->id,
            'file' => UploadedFile::fake()->create('statement.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $attachments = $draft->fresh()->attachments;
        $this->assertCount(1, $attachments);
        $this->assertSame('statement.pdf', $attachments[0]['filename']);
        Storage::disk('local')->assertExists($attachments[0]['path']);
    }

    public function test_an_oversized_upload_is_rejected(): void
    {
        Storage::fake('local');

        $this->actingAs($this->user)->post('/compose', $this->draftPayload());
        $draft = OutboundMessage::sole();

        $this->actingAs($this->user)->post('/compose/attach', [
            'outbound' => $draft->id,
            'file' => UploadedFile::fake()->create('huge.zip', 30 * 1024),
        ])->assertSessionHasErrors('file');
    }

    // ---- sending ----------------------------------------------------------

    public function test_sending_queues_the_job_and_marks_the_draft_queued(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->post('/compose', $this->draftPayload());
        $draft = OutboundMessage::sole();

        $this->actingAs($this->user)
            ->post("/compose/{$draft->id}/send", $this->draftPayload())
            ->assertRedirect();

        $this->assertSame(OutboundStatus::Queued, $draft->fresh()->status);
        Queue::assertPushed(SendMessageJob::class);
    }

    public function test_sending_without_a_recipient_is_refused(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->post('/compose', $this->draftPayload());
        $draft = OutboundMessage::sole();

        $this->actingAs($this->user)
            ->post("/compose/{$draft->id}/send", $this->draftPayload(['to' => []]))
            ->assertSessionHasErrors('to');

        $this->assertSame(OutboundStatus::Draft, $draft->fresh()->status);
        Queue::assertNotPushed(SendMessageJob::class);
    }

    public function test_the_send_job_hands_the_provider_a_draft_with_threading_headers(): void
    {
        Queue::fake();
        $parent = $this->incoming();

        $draft = OutboundMessage::create([
            'mail_account_id' => $this->workspace->id,
            'type' => OutboundType::Reply,
            'thread_id' => $parent->thread_id,
            'in_reply_to_message_id' => $parent->id,
            'to_addrs' => [['address' => 'anna@client.test', 'name' => 'Anna']],
            'subject' => 'Re: Invoice 2418',
            'body_html' => '<p>Looks good.</p>',
            'status' => OutboundStatus::Queued,
        ]);

        $this->runSend($draft);

        $sent = $this->provider->sent[0];
        $this->assertSame('<root@client.test>', $sent->inReplyTo);
        // References is the parent's chain plus the parent itself.
        $this->assertSame(['<older@client.test>', '<root@client.test>'], $sent->references);
        $this->assertSame('gmail-thread-9', $sent->providerThreadId);
        $this->assertSame('parent-1', $sent->replyToProviderMessageId);
    }

    public function test_a_successful_send_records_the_provider_id_and_pulls_the_sent_copy(): void
    {
        Queue::fake();
        $draft = $this->queuedDraft();

        $this->runSend($draft);

        $draft->refresh();
        $this->assertSame(OutboundStatus::Sent, $draft->status);
        $this->assertNotNull($draft->sent_message_id);
        $this->assertNotNull($draft->sent_at);

        // The sent copy arrives through ordinary sync from the Sent folder rather
        // than being written locally.
        Queue::assertPushed(SyncAccountJob::class);
    }

    public function test_a_retry_reuses_the_same_message_id(): void
    {
        // A duplicate delivery with the same Message-ID is collapsed by the
        // recipient's client; a new one arrives as a second email.
        Queue::fake();
        $draft = $this->queuedDraft();

        $this->runSend($draft);
        $first = $draft->fresh()->rfc822_message_id;

        $draft->update(['status' => OutboundStatus::Queued]);
        $this->runSend($draft->fresh());

        $this->assertSame($first, $draft->fresh()->rfc822_message_id);
    }

    public function test_an_already_sent_draft_is_not_sent_again(): void
    {
        Queue::fake();
        $draft = $this->queuedDraft();

        $this->runSend($draft);
        $this->runSend($draft->fresh());

        $this->assertCount(1, $this->provider->sent);
    }

    public function test_a_failed_send_is_recorded_on_the_draft(): void
    {
        $draft = $this->queuedDraft();

        (new SendMessageJob($draft))->failed(new \RuntimeException('mailbox full'));

        $draft->refresh();
        $this->assertSame(OutboundStatus::Failed, $draft->status);
        $this->assertSame('mailbox full', $draft->error);
    }

    public function test_a_send_rejected_on_auth_marks_the_account(): void
    {
        $draft = $this->queuedDraft();

        (new SendMessageJob($draft))->failed(new AuthenticationFailedException('token revoked'));

        $this->assertSame(AccountStatus::AuthError, $this->workspace->fresh()->status);
        $this->assertSame(OutboundStatus::Failed, $draft->fresh()->status);
    }

    public function test_a_missing_staged_upload_does_not_break_the_send(): void
    {
        // Staged files can be swept before the draft goes out; losing an attachment
        // beats failing the whole send.
        Queue::fake();
        $draft = $this->queuedDraft([
            'attachments' => [['path' => 'outbound/9/gone.pdf', 'filename' => 'gone.pdf']],
        ]);

        $this->runSend($draft);

        $this->assertSame(OutboundStatus::Sent, $draft->fresh()->status);
        $this->assertSame([], $this->provider->sent[0]->attachments);
    }

    public function test_a_thread_shows_mail_that_has_not_landed_yet(): void
    {
        // A send can sit in retry backoff for minutes. Without this the user clicks
        // Send, sees nothing appear, and cannot tell success from silent failure.
        $parent = $this->incoming();

        OutboundMessage::create([
            'mail_account_id' => $this->workspace->id,
            'type' => OutboundType::Reply,
            'thread_id' => $parent->thread_id,
            'to_addrs' => [['address' => 'anna@client.test', 'name' => null]],
            'subject' => 'Re: Invoice 2418',
            'status' => OutboundStatus::Failed,
            'attempts' => 3,
            'error' => 'mailbox unavailable',
        ]);

        $this->actingAs($this->user)->get("/threads/{$parent->thread_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('pending', 1)
                ->where('pending.0.status', 'failed')
                ->where('pending.0.error', 'mailbox unavailable'));
    }

    public function test_a_thread_does_not_list_mail_that_already_went_out(): void
    {
        $parent = $this->incoming();

        OutboundMessage::create([
            'mail_account_id' => $this->workspace->id,
            'type' => OutboundType::Reply,
            'thread_id' => $parent->thread_id,
            'status' => OutboundStatus::Sent,
            'subject' => 'Re: Invoice 2418',
        ]);

        $this->actingAs($this->user)->get("/threads/{$parent->thread_id}")
            ->assertInertia(fn (Assert $page) => $page->has('pending', 0));
    }

    private function queuedDraft(array $overrides = []): OutboundMessage
    {
        return OutboundMessage::create([
            'mail_account_id' => $this->workspace->id,
            'type' => OutboundType::New,
            'to_addrs' => [['address' => 'anna@client.test', 'name' => 'Anna']],
            'subject' => 'Hello',
            'body_html' => '<p>Hi</p>',
            'status' => OutboundStatus::Queued,
            ...$overrides,
        ]);
    }

    private function runSend(OutboundMessage $draft): void
    {
        (new SendMessageJob($draft))->handle(
            app(OutboundDraftFactory::class),
            app(MimeBuilder::class),
        );
    }
}

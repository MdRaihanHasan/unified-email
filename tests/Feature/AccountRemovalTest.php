<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Jobs\RemoveAccountJob;
use App\Mail\Data\Address;
use App\Mail\Data\RemoteMessage;
use App\Mail\Support\MessageWriter;
use App\Models\Folder;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AccountRemovalTest extends TestCase
{
    use RefreshDatabase;

    private MessageWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(MessageWriter::class);
    }

    /** Credentials stay empty so removal never reaches the Google revoke call. */
    private function account(): MailAccount
    {
        return MailAccount::factory()->gmailApi()->create(['credentials' => []]);
    }

    private function remote(string $id, array $overrides = []): RemoteMessage
    {
        return new RemoteMessage(...[
            'providerMessageId' => $id,
            'rfc822MessageId' => "<{$id}@example.com>",
            'from' => new Address('sender@example.com', 'Sender'),
            'to' => [new Address('me@company.com')],
            'subject' => 'Invoice 42',
            'snippet' => 'Please find attached',
            'receivedAt' => new \DateTimeImmutable('2026-08-01 09:00:00'),
            ...$overrides,
        ]);
    }

    public function test_removing_an_account_deletes_its_mail_and_its_now_empty_threads(): void
    {
        $account = $this->account();
        $this->writer->store($account, $this->remote('m1'));
        $this->writer->store($account, $this->remote('m2', ['inReplyTo' => '<m1@example.com>']));

        $this->actingAs(User::factory()->create())
            ->delete("/accounts/{$account->id}")
            ->assertRedirect('/accounts');

        $this->assertDatabaseMissing('mail_accounts', ['id' => $account->id]);
        $this->assertSame(0, Message::count());
        $this->assertSame(0, Thread::count());
        $this->assertSame(0, Folder::count());
    }

    public function test_a_thread_shared_with_another_mailbox_survives_with_its_counters_redone(): void
    {
        // Tier-1 threading merges on RFC Message-IDs across mailboxes, so one
        // thread can hold messages from two accounts. Removing one account must
        // shrink that thread, not delete it.
        $removed = $this->account();
        $kept = $this->account();

        $mine = $this->writer->store($removed, $this->remote('m1'));
        $theirs = $this->writer->store($kept, $this->remote('m2', [
            'inReplyTo' => '<m1@example.com>',
            'isRead' => false,
        ]));

        $this->assertSame($mine->thread_id, $theirs->thread_id, 'The fixture should produce one merged thread.');
        $this->assertSame(2, $mine->thread->fresh()->message_count);

        $this->actingAs(User::factory()->create())->delete("/accounts/{$removed->id}");

        $thread = Thread::findOrFail($theirs->thread_id);
        $this->assertSame(1, $thread->message_count);
        $this->assertSame(1, $thread->unread_count);
        $this->assertSame(1, Message::count());
    }

    public function test_removal_requires_a_signed_in_user(): void
    {
        $account = $this->account();

        $this->delete("/accounts/{$account->id}")->assertRedirect('/login');

        $this->assertDatabaseHas('mail_accounts', ['id' => $account->id]);
    }

    public function test_removal_is_queued_and_the_account_reads_as_removing_meanwhile(): void
    {
        Queue::fake();

        $account = $this->account();
        $this->writer->store($account, $this->remote('m1'));

        $this->actingAs(User::factory()->create())->delete("/accounts/{$account->id}");

        // The request only marks and dispatches; the rows go in the job.
        $account->refresh();
        $this->assertNotNull($account->removal_requested_at);
        $this->assertSame('disabled', $account->status->value);
        $this->assertSame(1, Message::count());
        Queue::assertPushed(RemoveAccountJob::class);
    }

    // ---- editing (F-25) -----------------------------------------------------

    public function test_label_sender_name_and_signature_are_editable(): void
    {
        $account = $this->account();

        $this->actingAs(User::factory()->create())->patch("/accounts/{$account->id}", [
            'label' => 'Bixcel HQ',
            'display_name' => 'Raihan at Bixcel',
            'signature_html' => '<p>— Raihan</p>',
        ])->assertRedirect();

        $account->refresh();
        $this->assertSame('Bixcel HQ', $account->label);
        $this->assertSame('Raihan at Bixcel', $account->display_name);
        $this->assertSame('<p>— Raihan</p>', $account->signature_html);
    }

    public function test_pause_and_resume_toggle_sync_eligibility(): void
    {
        $account = $this->account();
        $user = User::factory()->create();

        $this->actingAs($user)->patch("/accounts/{$account->id}", ['paused' => true]);
        $this->assertFalse($account->fresh()->status->shouldSync());

        $this->actingAs($user)->patch("/accounts/{$account->id}", ['paused' => false]);
        $this->assertTrue($account->fresh()->status->shouldSync());
    }

    public function test_pausing_does_not_paper_over_an_auth_error(): void
    {
        $account = $this->account();
        $account->update(['status' => AccountStatus::AuthError]);

        $this->actingAs(User::factory()->create())
            ->patch("/accounts/{$account->id}", ['paused' => false]);

        $this->assertSame('auth_error', $account->fresh()->status->value);
    }
}

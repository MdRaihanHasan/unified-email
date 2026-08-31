<?php

namespace Tests\Feature;

use App\Enums\FolderRole;
use App\Enums\OutboundType;
use App\Jobs\PushFlagsJob;
use App\Jobs\SyncAccountJob;
use App\Mail\Data\Address;
use App\Mail\Data\ChangeSet;
use App\Mail\Data\MessageUpdate;
use App\Mail\Data\RemoteMessage;
use App\Mail\Support\MessageWriter;
use App\Mail\Support\OutboundDraftFactory;
use App\Models\Folder;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\OutboundMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\UsesFakeProvider;
use Tests\TestCase;

/**
 * Companion to AuditFindingsTest: the smaller fixes shipped from the same audit.
 */
class AuditFixesTest extends TestCase
{
    use RefreshDatabase, UsesFakeProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeProvider();
    }

    private function remote(string $id, array $overrides = []): RemoteMessage
    {
        return new RemoteMessage(...[
            'providerMessageId' => $id,
            'rfc822MessageId' => "<{$id}@example.com>",
            'from' => new Address('sender@example.com'),
            'subject' => "Message {$id}",
            'receivedAt' => new \DateTimeImmutable('2026-08-01 09:00:00'),
            ...$overrides,
        ]);
    }

    /**
     * A label created in Gmail after connect is unknown here, but its messages
     * still have to be filed somewhere — silently dropping the unknown id used to
     * detach the message from every folder at once.
     */
    public function test_a_label_created_after_connect_still_files_the_message(): void
    {
        $account = MailAccount::factory()->gmailApi()->create();
        $writer = app(MessageWriter::class);

        $message = $writer->store($account, $this->remote('m-1', [
            'folderRemoteIds' => ['Label_created_later'],
        ]));

        $folder = Folder::query()
            ->where('mail_account_id', $account->id)
            ->where('remote_id', 'Label_created_later')
            ->first();

        $this->assertNotNull($folder, 'the unknown label becomes a folder row on the fly');
        $this->assertSame(FolderRole::Custom, $folder->role);
        $this->assertTrue($message->folders()->whereKey($folder->id)->exists());

        // And the label being removed again empties the pivot rather than being
        // ignored (the archive fix, exercised through the same writer path).
        $writer->applyChangeSet($account, new ChangeSet(
            updated: [new MessageUpdate(providerMessageId: 'm-1', folderRemoteIds: [])],
        ));

        $this->assertSame(0, $message->fresh()->folders()->count());
    }

    /**
     * A Gmail thread id only exists inside the mailbox that issued it. A reply
     * sent from a different connected account used to carry the parent's id and
     * the provider rejected the send.
     */
    public function test_a_cross_account_reply_does_not_carry_the_parents_thread_id(): void
    {
        $receiving = MailAccount::factory()->gmailApi()->create();
        $sending = MailAccount::factory()->gmailApi()->create();

        $parent = Message::factory()->for($receiving, 'mailAccount')->create([
            'provider_thread_id' => 'thread-of-the-receiving-mailbox',
        ]);

        $factory = app(OutboundDraftFactory::class);

        $crossAccount = OutboundMessage::create([
            'mail_account_id' => $sending->id,
            'thread_id' => $parent->thread_id,
            'in_reply_to_message_id' => $parent->id,
            'type' => OutboundType::Reply,
            'to_addrs' => [['address' => 'other@example.com', 'name' => null]],
            'subject' => 'Re: hello',
            'body_html' => '<p>hi</p>',
        ]);

        $this->assertNull(
            $factory->from($crossAccount)->providerThreadId,
            'a foreign thread id would make the provider reject the send; headers carry the threading',
        );

        $sameAccount = OutboundMessage::create([
            'mail_account_id' => $receiving->id,
            'thread_id' => $parent->thread_id,
            'in_reply_to_message_id' => $parent->id,
            'type' => OutboundType::Reply,
            'to_addrs' => [['address' => 'other@example.com', 'name' => null]],
            'subject' => 'Re: hello',
            'body_html' => '<p>hi</p>',
        ]);

        $this->assertSame(
            'thread-of-the-receiving-mailbox',
            $factory->from($sameAccount)->providerThreadId,
            'replying from the mailbox that holds the conversation keeps the fast path',
        );
    }

    public function test_login_attempts_are_throttled(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);

        foreach (range(1, 5) as $i) {
            $this->post('/login', ['email' => 'owner@example.com', 'password' => 'wrong-'.$i]);
        }

        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'wrong-6'])
            ->assertInvalid(['email' => 'Too many attempts']);
    }

    public function test_sync_now_queues_a_sync_for_every_active_account(): void
    {
        Queue::fake();

        $active = MailAccount::factory()->gmailApi()->create();
        MailAccount::factory()->gmailApi()->create(['status' => 'disabled']);

        $this->actingAs(User::factory()->create())
            ->post('/sync')
            ->assertRedirect()
            ->assertSessionHas('message');

        Queue::assertPushed(SyncAccountJob::class, 1);
        Queue::assertPushed(fn (SyncAccountJob $job) => $job->account->is($active));
    }

    /**
     * Opening a thread marks it read implicitly; that call passes quiet so the
     * user is not told "Marked 1 conversation read." every time they read mail.
     */
    public function test_quiet_thread_actions_skip_the_flash_and_still_push_flags(): void
    {
        Queue::fake();

        $account = MailAccount::factory()->gmailApi()->create();
        $message = Message::factory()->for($account, 'mailAccount')->create(['is_read' => false]);

        $this->actingAs(User::factory()->create())
            ->post('/threads/actions', [
                'thread_ids' => [$message->thread_id],
                'action' => 'read',
                'quiet' => true,
            ])
            ->assertRedirect()
            ->assertSessionMissing('message');

        $this->assertTrue($message->fresh()->is_read);

        Queue::assertPushed(
            fn (PushFlagsJob $job) => $job->queue === 'interactive',
            1,
        );
    }
}

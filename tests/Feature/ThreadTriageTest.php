<?php

namespace Tests\Feature;

use App\Enums\FolderRole;
use App\Enums\MoveAction;
use App\Jobs\PushMoveJob;
use App\Mail\Data\Address;
use App\Mail\Data\RemoteFolder;
use App\Mail\Data\RemoteMessage;
use App\Mail\Support\MessageWriter;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\UsesFakeProvider;
use Tests\TestCase;

class ThreadTriageTest extends TestCase
{
    use RefreshDatabase, UsesFakeProvider;

    private MessageWriter $writer;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeProvider();
        $this->writer = app(MessageWriter::class);
        $this->account = MailAccount::factory()->gmailApi()->create();

        $this->writer->storeFolders($this->account, [
            new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox, isLabel: true),
            new RemoteFolder('SPAM', 'Spam', FolderRole::Junk, isLabel: true),
            new RemoteFolder('TRASH', 'Trash', FolderRole::Trash, isLabel: true),
        ]);

        $this->actingAs(User::factory()->create());
    }

    private function store(string $id, string $folder = 'INBOX'): Message
    {
        return $this->writer->store($this->account, new RemoteMessage(
            providerMessageId: $id,
            rfc822MessageId: "<{$id}@example.com>",
            from: new Address('sender@example.com'),
            subject: "Message {$id}",
            receivedAt: new \DateTimeImmutable('2026-08-01 09:00:00'),
            isRead: false,
            folderRemoteIds: [$folder],
        ));
    }

    private function act(array $threadIds, string $action)
    {
        return $this->post('/threads/actions', ['thread_ids' => $threadIds, 'action' => $action]);
    }

    private function roles(Message $message): array
    {
        return $message->folders()->pluck('role')->map(fn ($r) => $r->value)->sort()->values()->all();
    }

    public function test_archiving_detaches_the_inbox_and_pushes_to_the_provider(): void
    {
        $message = $this->store('m1');

        $this->act([$message->thread_id], 'archive')->assertRedirect();

        $this->assertSame([], $this->roles($message));
        $this->assertNotContains($message->thread_id, Thread::inView('inbox')->pluck('id')->all());
        $this->assertContains($message->thread_id, Thread::inView('all')->pluck('id')->all());

        $move = collect($this->provider->appliedMoves)->sole();
        $this->assertSame(MoveAction::Archive, $move['action']);
        $this->assertSame(['m1'], $move['ids']);
    }

    public function test_trashing_moves_the_message_to_trash_and_out_of_unread(): void
    {
        $message = $this->store('m1');
        $this->assertSame(1, $message->thread->fresh()->unread_count);

        $this->act([$message->thread_id], 'trash');

        $this->assertSame(['trash'], $this->roles($message));
        $this->assertSame(0, $message->thread->fresh()->unread_count);
        $this->assertContains($message->thread_id, Thread::inView('trash')->pluck('id')->all());
        $this->assertSame(MoveAction::Trash, collect($this->provider->appliedMoves)->sole()['action']);
    }

    public function test_spam_and_restore_round_trip(): void
    {
        $message = $this->store('m1');

        $this->act([$message->thread_id], 'spam');
        $this->assertSame(['junk'], $this->roles($message));

        $this->act([$message->thread_id], 'restore');
        $this->assertSame(['inbox'], $this->roles($message));
        $this->assertContains($message->thread_id, Thread::inView('inbox')->pluck('id')->all());
    }

    public function test_a_refused_move_restores_the_pivot_rows_exactly(): void
    {
        $message = $this->store('m1');
        $before = $this->roles($message);

        $this->provider->moveFailure = new \RuntimeException('Gmail said no');

        $this->act([$message->thread_id], 'archive');

        // The local move happened optimistically, then the job's failure handler
        // put the pivots back.
        $this->assertSame($before, $this->roles($message));
        $this->assertContains($message->thread_id, Thread::inView('inbox')->pluck('id')->all());
    }

    public function test_a_cross_account_thread_moves_in_both_mailboxes_with_one_job_each(): void
    {
        Queue::fake();

        $other = MailAccount::factory()->gmailApi()->create();
        $this->writer->storeFolders($other, [
            new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox, isLabel: true),
        ]);

        $mine = $this->store('m1');
        $theirs = $this->writer->store($other, new RemoteMessage(
            providerMessageId: 'm2',
            rfc822MessageId: '<m2@example.com>',
            inReplyTo: '<m1@example.com>',
            from: new Address('sender@example.com'),
            folderRemoteIds: ['INBOX'],
        ));

        $this->assertSame($mine->thread_id, $theirs->thread_id);

        $this->act([$mine->thread_id], 'archive');

        $this->assertSame([], $this->roles($mine));
        $this->assertSame([], $this->roles($theirs));
        Queue::assertPushed(PushMoveJob::class, 2);
    }

    public function test_moves_ride_the_interactive_queue(): void
    {
        Queue::fake();

        $message = $this->store('m1');

        $this->act([$message->thread_id], 'archive');

        Queue::assertPushedOn('interactive', PushMoveJob::class);
    }
}

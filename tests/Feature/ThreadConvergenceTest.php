<?php

namespace Tests\Feature;

use App\Jobs\PushFlagsJob;
use App\Mail\Data\Address;
use App\Mail\Data\RemoteMessage;
use App\Mail\Support\MessageWriter;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ThreadConvergenceTest extends TestCase
{
    use RefreshDatabase;

    private MessageWriter $writer;

    private MailAccount $a;

    private MailAccount $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(MessageWriter::class);
        $this->a = MailAccount::factory()->gmailApi()->create(['label' => 'A']);
        $this->b = MailAccount::factory()->gmailApi()->create(['label' => 'B']);
    }

    private function remote(string $id, array $overrides = []): RemoteMessage
    {
        return new RemoteMessage(...[
            'providerMessageId' => $id,
            'rfc822MessageId' => "<{$id}@example.com>",
            'from' => new Address('sender@example.com', 'Sender'),
            'subject' => 'One conversation',
            'receivedAt' => new \DateTimeImmutable('2026-08-01 09:00:00'),
            ...$overrides,
        ]);
    }

    // ---- F-09: merge on late evidence ----------------------------------------

    public function test_late_evidence_merges_threads_that_resolved_apart(): void
    {
        // Root lands in A; a grandchild lands in B referencing only its (not yet
        // seen) parent — an unlucky order that used to split permanently.
        $root = $this->writer->store($this->a, $this->remote('root', ['subject' => 'Root']));
        $grandchild = $this->writer->store($this->b, $this->remote('grandchild', [
            'inReplyTo' => '<middle@example.com>',
            'subject' => 'Re: Root',
        ]));

        $this->assertNotSame($root->thread_id, $grandchild->thread_id, 'the split is the fixture');

        // The middle message arrives, proving both threads are one conversation.
        $middle = $this->writer->store($this->a, $this->remote('middle', [
            'inReplyTo' => '<root@example.com>',
            'subject' => 'Re: Root',
        ]));

        $this->assertSame(1, Thread::count(), 'the loser thread is gone');

        $thread = Thread::sole();
        $this->assertSame(
            ['grandchild', 'middle', 'root'],
            Message::pluck('provider_message_id')->sort()->values()->all(),
        );
        $this->assertSame($thread->id, $middle->fresh()->thread_id);
        $this->assertSame($thread->id, $root->fresh()->thread_id);
        $this->assertSame($thread->id, $grandchild->fresh()->thread_id);
        $this->assertSame(3, $thread->message_count);
    }

    // ---- F-10: one card per Message-ID ----------------------------------------

    private function storeCopies(): array
    {
        $mine = $this->writer->store($this->a, $this->remote('copy-a', [
            'isRead' => false, 'bodyHtml' => '<p>Hello</p>',
        ]));
        $theirs = $this->writer->store($this->b, $this->remote('copy-b', [
            'rfc822MessageId' => '<copy-a@example.com>', // the same RFC message
            'isRead' => false,
        ]));

        $this->assertSame($mine->thread_id, $theirs->thread_id);

        return [$mine, $theirs];
    }

    public function test_two_mailbox_copies_render_as_one_message_with_both_chips(): void
    {
        [$mine] = $this->storeCopies();

        $page = $this->actingAs(User::factory()->create())
            ->get("/inbox?thread={$mine->thread_id}")
            ->inertiaPage();

        $messages = $page['props']['open']['messages'];

        $this->assertCount(1, $messages);
        $this->assertEqualsCanonicalizing(
            ['A', 'B'],
            array_column($messages[0]['accounts'], 'label'),
        );
        // The copy with the body is the one rendered.
        $this->assertStringContainsString('Hello', $messages[0]['body_html']);
    }

    public function test_message_count_counts_conversations_not_copies(): void
    {
        [$mine] = $this->storeCopies();

        $this->assertSame(1, $mine->thread->fresh()->message_count);
    }

    public function test_a_flag_flip_reaches_every_copy_with_one_push_per_account(): void
    {
        Queue::fake();

        [$mine, $theirs] = $this->storeCopies();

        $this->actingAs(User::factory()->create())
            ->patch("/messages/{$mine->id}/flags", ['is_read' => true]);

        $this->assertTrue($mine->fresh()->is_read);
        $this->assertTrue($theirs->fresh()->is_read, 'the other mailbox copy must follow');
        Queue::assertPushed(PushFlagsJob::class, 2);
    }
}

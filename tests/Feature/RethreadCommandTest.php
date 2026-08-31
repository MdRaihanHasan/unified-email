<?php

namespace Tests\Feature;

use App\Models\MailAccount;
use App\Models\Message;
use App\Models\Thread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RethreadCommandTest extends TestCase
{
    use RefreshDatabase;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = MailAccount::factory()->gmailApi()->create();
    }

    private function message(Thread $thread, string $providerThreadId, array $overrides = []): Message
    {
        return Message::factory()->for($this->account, 'mailAccount')->create([
            'thread_id' => $thread->id,
            'provider_thread_id' => $providerThreadId,
            'in_reply_to' => null,
            'references_ids' => [],
            ...$overrides,
        ]);
    }

    public function test_a_subject_merge_of_two_provider_threads_is_split_apart(): void
    {
        $thread = Thread::factory()->create();

        // The 144-message live case in miniature: same subject, same robot sender,
        // two conversations by the provider's own reckoning, no headers linking them.
        $kept = $this->message($thread, 'gmail-1', ['received_at' => now()->subDays(3), 'is_read' => true]);
        $this->message($thread, 'gmail-1', ['received_at' => now()->subDays(2), 'is_read' => true]);
        $moved = $this->message($thread, 'gmail-2', ['received_at' => now()->subDay(), 'is_read' => false]);

        $this->artisan('mail:rethread')->assertSuccessful();

        $this->assertSame(2, Thread::count(), 'one thread per provider conversation');
        $this->assertSame($thread->id, $kept->fresh()->thread_id, 'the oldest group keeps the original row');
        $this->assertNotSame($thread->id, $moved->fresh()->thread_id);

        // Derived counters were recomputed on both sides of the split.
        $this->assertSame(2, $thread->fresh()->message_count);
        $this->assertSame(0, $thread->fresh()->unread_count);
        $this->assertSame(1, $moved->fresh()->thread->message_count);
        $this->assertSame(1, $moved->fresh()->thread->unread_count);
    }

    public function test_threads_joined_by_references_are_left_alone(): void
    {
        $thread = Thread::factory()->create();

        // Two provider thread ids, but the second message references the first:
        // headers joined these deliberately (Gmail sometimes splits what RFC
        // threading joins — subject changes, very old threads). Not ours to undo.
        $root = $this->message($thread, 'gmail-1', [
            'rfc822_message_id' => '<root@example.com>',
        ]);
        $this->message($thread, 'gmail-2', [
            'in_reply_to' => '<root@example.com>',
            'references_ids' => ['<root@example.com>'],
        ]);

        $this->artisan('mail:rethread')->assertSuccessful();

        $this->assertSame(1, Thread::count());
        $this->assertSame($thread->id, $root->fresh()->thread_id);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $thread = Thread::factory()->create();
        $this->message($thread, 'gmail-1', ['received_at' => now()->subDay()]);
        $this->message($thread, 'gmail-2', ['received_at' => now()]);

        $this->artisan('mail:rethread --dry-run')->assertSuccessful();

        $this->assertSame(1, Thread::count(), 'dry run must not create threads');
    }

    public function test_cross_account_stitching_is_never_split(): void
    {
        // A conversation legitimately stitched across two mailboxes (tier 1) holds
        // two provider thread ids — one per account. That is the app's marquee
        // feature, not damage; only conflicts WITHIN one account are suspect.
        $other = MailAccount::factory()->gmailApi()->create();
        $thread = Thread::factory()->create();

        Message::factory()->for($this->account, 'mailAccount')->create([
            'thread_id' => $thread->id,
            'provider_thread_id' => 'mine-1',
            'in_reply_to' => null,
            'references_ids' => [],
        ]);
        Message::factory()->for($other, 'mailAccount')->create([
            'thread_id' => $thread->id,
            'provider_thread_id' => 'theirs-1',
            'in_reply_to' => null,
            'references_ids' => [],
        ]);

        $this->artisan('mail:rethread')->assertSuccessful();

        $this->assertSame(1, Thread::count());
    }
}

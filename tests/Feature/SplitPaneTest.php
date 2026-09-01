<?php

namespace Tests\Feature;

use App\Enums\FolderRole;
use App\Jobs\PushFlagsJob;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\UsesFakeProvider;
use Tests\TestCase;

class SplitPaneTest extends TestCase
{
    use RefreshDatabase, UsesFakeProvider;

    private User $user;

    private MailAccount $workspace;

    private MailAccount $outlook;

    private MessageWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeProvider();
        $this->user = User::factory()->create();
        $this->writer = app(MessageWriter::class);

        $this->workspace = MailAccount::factory()->gmailApi()->create(['label' => 'Work', 'email' => 'me@company.com']);
        $this->outlook = MailAccount::factory()->create(['label' => 'Personal', 'email' => 'me@outlook.com']);

        foreach ([$this->workspace, $this->outlook] as $account) {
            $this->writer->storeFolders($account, [
                new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox),
            ]);
        }
    }

    private function store(MailAccount $account, string $id, array $overrides = []): Message
    {
        return $this->writer->store($account, new RemoteMessage(...[
            'providerMessageId' => $id,
            'rfc822MessageId' => "<{$id}@example.com>",
            'from' => new Address('sender@example.com', 'A Sender'),
            'to' => [new Address($account->email)],
            'subject' => "Message {$id}",
            'snippet' => "Snippet for {$id}",
            'receivedAt' => new \DateTimeImmutable('2026-08-01 09:00:00'),
            'folderRemoteIds' => ['INBOX'],
            ...$overrides,
        ]));
    }

    // ---- one screen -------------------------------------------------------

    public function test_the_list_renders_with_no_thread_open(): void
    {
        $this->store($this->workspace, 'a');

        $this->actingAs($this->user)->get('/inbox')->assertInertia(fn (Assert $page) => $page
            ->component('Inbox/Index')
            ->has('threads.data', 1)
            ->where('open', null));
    }

    public function test_selecting_a_thread_keeps_the_list_and_adds_the_thread(): void
    {
        // The point of the layout: opening mail is not a navigation to another page.
        $first = $this->store($this->workspace, 'a');
        $this->store($this->workspace, 'b', ['rfc822MessageId' => '<b@x>']);

        $this->actingAs($this->user)
            ->get('/inbox?thread='.$first->thread_id)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/Index')
                ->has('threads.data', 2)
                ->where('open.thread.id', $first->thread_id)
                ->has('open.messages', 1));
    }

    public function test_the_thread_url_stays_a_working_deep_link(): void
    {
        $message = $this->store($this->workspace, 'a');

        $this->actingAs($this->user)
            ->get("/threads/{$message->thread_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/Index')
                ->has('threads.data', 1)
                ->where('open.thread.id', $message->thread_id));
    }

    public function test_a_deep_link_keeps_the_view_it_was_given(): void
    {
        $message = $this->store($this->workspace, 'a', ['isStarred' => true]);

        $this->actingAs($this->user)
            ->get("/threads/{$message->thread_id}?view=starred")
            ->assertInertia(fn (Assert $page) => $page->where('filters.view', 'starred'));
    }

    public function test_an_unknown_thread_is_rejected_rather_than_opening_blank(): void
    {
        $this->actingAs($this->user)->get('/inbox?thread=9999')->assertSessionHasErrors('thread');
    }

    public function test_the_open_thread_names_every_mailbox_it_spans(): void
    {
        // A thread stitched across accounts is the thing a merged inbox exists for,
        // so the pane says so rather than picking one.
        $original = $this->store($this->outlook, 'o1', ['rfc822MessageId' => '<orig@x>']);
        $this->store($this->workspace, 'w1', [
            'rfc822MessageId' => '<reply@x>',
            'references' => ['<orig@x>'],
        ]);

        $this->actingAs($this->user)
            ->get('/inbox?thread='.$original->thread_id)
            ->assertInertia(fn (Assert $page) => $page->has('open.thread.providers', 2));
    }

    public function test_sidebar_counts_are_shared_with_every_screen(): void
    {
        $this->store($this->workspace, 'unread', ['isRead' => false]);
        $this->store($this->workspace, 'read', ['isRead' => true, 'rfc822MessageId' => '<r@x>']);
        $this->store($this->workspace, 'starred', ['isRead' => true, 'isStarred' => true, 'rfc822MessageId' => '<s@x>']);

        $this->actingAs($this->user)->get('/inbox')->assertInertia(fn (Assert $page) => $page
            ->where('counts.unread', 1)
            ->where('counts.inbox', 1)
            ->where('counts.starred', 1));
    }

    // ---- bulk actions -----------------------------------------------------

    public function test_bulk_marking_read_updates_locally_and_queues_one_push_per_account(): void
    {
        // One job per account: each provider is a separate connection and a separate
        // set of ids, and a failure on one must not revert the others.
        Queue::fake();
        $a = $this->store($this->workspace, 'a', ['isRead' => false]);
        $b = $this->store($this->outlook, 'b', ['isRead' => false, 'rfc822MessageId' => '<b@x>']);

        $this->actingAs($this->user)->post('/threads/actions', [
            'thread_ids' => [$a->thread_id, $b->thread_id],
            'action' => 'read',
        ])->assertRedirect();

        $this->assertTrue($a->fresh()->is_read);
        $this->assertTrue($b->fresh()->is_read);
        $this->assertSame(0, Thread::where('unread_count', '>', 0)->count());

        Queue::assertPushed(PushFlagsJob::class, 2);
    }

    public function test_bulk_starring_sets_the_thread_flag(): void
    {
        Queue::fake();
        $message = $this->store($this->workspace, 'a');

        $this->actingAs($this->user)->post('/threads/actions', [
            'thread_ids' => [$message->thread_id],
            'action' => 'star',
        ]);

        $this->assertTrue($message->fresh()->is_starred);
        $this->assertTrue(Thread::find($message->thread_id)->is_starred);
    }

    public function test_a_bulk_push_carries_the_values_from_before_the_change(): void
    {
        // A failed push has to put each message back to what it actually was, not to
        // an assumed opposite.
        Queue::fake();
        $message = $this->store($this->workspace, 'a', ['isRead' => false, 'isStarred' => true]);

        $this->actingAs($this->user)->post('/threads/actions', [
            'thread_ids' => [$message->thread_id],
            'action' => 'read',
        ]);

        Queue::assertPushed(PushFlagsJob::class, fn (PushFlagsJob $job) => $job->previous['a']['is_read'] === false
            && $job->previous['a']['is_starred'] === true);
    }

    public function test_an_unsupported_bulk_action_is_refused(): void
    {
        // Archive and friends are real actions now (ThreadTriageTest covers them);
        // a typo'd action must still be a validation error, not a silent no-op.
        Queue::fake();
        $message = $this->store($this->workspace, 'a');

        $this->actingAs($this->user)->post('/threads/actions', [
            'thread_ids' => [$message->thread_id],
            'action' => 'snooze',
        ])->assertSessionHasErrors('action');

        Queue::assertNothingPushed();
    }

    public function test_bulk_actions_need_at_least_one_thread(): void
    {
        $this->actingAs($this->user)
            ->post('/threads/actions', ['thread_ids' => [], 'action' => 'read'])
            ->assertSessionHasErrors('thread_ids');
    }

    public function test_a_guest_cannot_run_bulk_actions(): void
    {
        $message = $this->store($this->workspace, 'a');

        $this->post('/threads/actions', ['thread_ids' => [$message->thread_id], 'action' => 'read'])
            ->assertRedirect('/login');
    }

    // ---- composer prefill endpoint ---------------------------------------

    public function test_the_prefill_endpoint_returns_recipients_and_a_quote(): void
    {
        // The inline reply box is not a page, but recipient resolution and quoting
        // stay server-side rather than being reimplemented in JavaScript.
        $message = $this->store($this->workspace, 'a', [
            'from' => new Address('anna@client.test', 'Anna'),
            'bodyHtml' => '<p>Original</p><img src="https://tracker.test/p.gif">',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/compose/prefill?type=reply&message={$message->id}")
            ->assertOk();

        $draft = $response->json('draft');

        $this->assertSame('anna@client.test', $draft['to'][0]['address']);
        $this->assertSame('Re: Message a', $draft['subject']);
        $this->assertStringContainsString('Original', $draft['body_html']);
        $this->assertStringNotContainsString('tracker.test', $draft['body_html']);
        $this->assertNotEmpty($response->json('accounts'));
    }

    public function test_the_prefill_endpoint_reports_a_missing_parent(): void
    {
        $this->actingAs($this->user)
            ->getJson('/compose/prefill?type=reply&message=9999')
            ->assertStatus(422);
    }

    public function test_the_prefill_endpoint_reports_having_no_mailbox(): void
    {
        MailAccount::query()->delete();

        $this->actingAs($this->user)
            ->getJson('/compose/prefill')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Connect a mailbox before composing.');
    }
}

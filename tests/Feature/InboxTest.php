<?php

namespace Tests\Feature;

use App\Enums\FolderRole;
use App\Jobs\PushFlagsJob;
use App\Mail\Data\Address;
use App\Mail\Data\RemoteAttachment;
use App\Mail\Data\RemoteFolder;
use App\Mail\Data\RemoteMessage;
use App\Mail\Support\MessageWriter;
use App\Models\Folder;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\UsesFakeProvider;
use Tests\TestCase;

class InboxTest extends TestCase
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
                new RemoteFolder('SENT', 'Sent', FolderRole::Sent),
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
            'receivedAt' => new \DateTimeImmutable('2026-08-01 09:00:00'),
            'folderRemoteIds' => ['INBOX'],
            ...$overrides,
        ]));
    }

    // ---- access -----------------------------------------------------------

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/inbox')->assertRedirect('/login');
        $this->get('/')->assertRedirect('/login');
    }

    public function test_there_is_no_registration_route(): void
    {
        // Single-user instance: the only way in is `php artisan mail:user`.
        $this->post('/register')->assertNotFound();
        $this->get('/register')->assertNotFound();
    }

    public function test_signing_in_lands_on_the_inbox(): void
    {
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])
            ->assertRedirect('/inbox');

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_bad_credentials_are_rejected(): void
    {
        $this->post('/login', ['email' => $this->user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // ---- inbox list -------------------------------------------------------

    public function test_the_inbox_merges_threads_from_every_account(): void
    {
        $this->store($this->workspace, 'w1');
        $this->store($this->outlook, 'o1');

        $this->actingAs($this->user)->get('/inbox')->assertInertia(fn (Assert $page) => $page
            ->component('Inbox/Index')
            ->has('threads.data', 2));
    }

    public function test_the_inbox_view_excludes_mail_that_is_not_in_an_inbox_folder(): void
    {
        $this->store($this->workspace, 'inboxed', ['folderRemoteIds' => ['INBOX']]);
        $this->store($this->workspace, 'sent', ['folderRemoteIds' => ['SENT'], 'rfc822MessageId' => '<sent@x>']);

        $this->actingAs($this->user)->get('/inbox')->assertInertia(fn (Assert $page) => $page
            ->has('threads.data', 1)
            ->where('threads.data.0.subject', 'Message inboxed'));
    }

    public function test_the_sent_view_shows_only_sent_mail(): void
    {
        $this->store($this->workspace, 'inboxed');
        $this->store($this->workspace, 'sent', ['folderRemoteIds' => ['SENT'], 'rfc822MessageId' => '<sent@x>']);

        $this->actingAs($this->user)->get('/inbox?view=sent')->assertInertia(fn (Assert $page) => $page
            ->has('threads.data', 1)
            ->where('threads.data.0.subject', 'Message sent'));
    }

    public function test_the_unread_view_filters_on_unread_count(): void
    {
        $this->store($this->workspace, 'unread', ['isRead' => false]);
        $this->store($this->workspace, 'read', ['isRead' => true, 'rfc822MessageId' => '<read@x>']);

        $this->actingAs($this->user)->get('/inbox?view=unread')->assertInertia(fn (Assert $page) => $page
            ->has('threads.data', 1)
            ->where('threads.data.0.subject', 'Message unread'));
    }

    public function test_the_starred_view_filters_on_starred(): void
    {
        $this->store($this->workspace, 'plain');
        $this->store($this->workspace, 'starred', ['isStarred' => true, 'rfc822MessageId' => '<starred@x>']);

        $this->actingAs($this->user)->get('/inbox?view=starred')->assertInertia(fn (Assert $page) => $page
            ->has('threads.data', 1)
            ->where('threads.data.0.subject', 'Message starred'));
    }

    public function test_the_list_can_be_narrowed_to_one_account(): void
    {
        $this->store($this->workspace, 'w1');
        $this->store($this->outlook, 'o1');

        $this->actingAs($this->user)
            ->get('/inbox?view=all&account='.$this->outlook->id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('threads.data', 1)
                ->where('threads.data.0.subject', 'Message o1'));
    }

    public function test_search_matches_on_body_text(): void
    {
        $this->store($this->workspace, 'a', ['bodyText' => 'The reconciliation spreadsheet is attached.']);
        $this->store($this->workspace, 'b', ['bodyText' => 'Thursday works for me.', 'rfc822MessageId' => '<b@x>']);

        $this->actingAs($this->user)
            ->get('/inbox?view=all&q=reconciliation')
            ->assertInertia(fn (Assert $page) => $page
                ->has('threads.data', 1)
                ->where('threads.data.0.subject', 'Message a'));
    }

    public function test_a_malformed_search_query_does_not_error(): void
    {
        // websearch_to_tsquery accepts whatever a person types; to_tsquery would
        // raise a syntax error and 500 the page.
        $this->store($this->workspace, 'a', ['bodyText' => 'hello']);

        foreach (['"unbalanced', 'a & | b', '!!!', 'from: '] as $query) {
            $this->actingAs($this->user)->get('/inbox?view=all&q='.urlencode($query))->assertOk();
        }
    }

    public function test_an_unknown_account_filter_is_rejected(): void
    {
        $this->actingAs($this->user)->get('/inbox?account=9999')->assertSessionHasErrors('account');
    }

    public function test_accounts_and_staleness_are_shared_with_every_page(): void
    {
        $this->workspace->update(['last_synced_at' => now()->subHours(3)]);

        $this->actingAs($this->user)->get('/inbox')->assertInertia(fn (Assert $page) => $page
            ->has('accounts', 2)
            ->where('accounts.0.is_stale', true)
            ->where('accounts.1.is_stale', false));
    }

    // ---- thread view ------------------------------------------------------

    public function test_a_thread_renders_its_messages_oldest_first(): void
    {
        $first = $this->store($this->workspace, 'm1', ['receivedAt' => new \DateTimeImmutable('2026-08-01 09:00:00')]);
        $this->store($this->workspace, 'm2', [
            'receivedAt' => new \DateTimeImmutable('2026-08-02 09:00:00'),
            'rfc822MessageId' => '<m2@x>',
            'inReplyTo' => '<m1@example.com>',
        ]);

        $this->actingAs($this->user)->get("/threads/{$first->thread_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/Index')
                ->has('open.messages', 2)
                ->where('open.messages.0.subject', 'Message m1')
                ->where('open.messages.1.subject', 'Message m2'));
    }

    public function test_a_thread_body_is_sanitised_before_it_reaches_the_page(): void
    {
        $message = $this->store($this->workspace, 'm1', [
            'bodyHtml' => '<p>Hello</p><script>alert(1)</script>',
        ]);

        $this->actingAs($this->user)->get("/threads/{$message->thread_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('open.messages.0.body_html', fn (string $html) => ! str_contains($html, 'script')
                    && str_contains($html, 'Hello')));
    }

    public function test_remote_images_are_blocked_until_asked_for_and_only_per_message(): void
    {
        // Agreeing to load images in one message must not enable tracking pixels in
        // the rest of the thread.
        $first = $this->store($this->workspace, 'm1', [
            'bodyHtml' => '<img src="https://tracker.test/1.gif">',
        ]);
        $this->store($this->workspace, 'm2', [
            'bodyHtml' => '<img src="https://tracker.test/2.gif">',
            'rfc822MessageId' => '<m2@x>',
            'inReplyTo' => '<m1@example.com>',
        ]);

        $this->actingAs($this->user)->get("/threads/{$first->thread_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('open.messages.0.blocked_images', 1)
                ->where('open.messages.1.blocked_images', 1));

        $this->actingAs($this->user)->get("/threads/{$first->thread_id}?show_images={$first->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('open.messages.0.blocked_images', 0)
                ->where('open.messages.1.blocked_images', 1, 'the other message stays blocked'));
    }

    public function test_a_message_without_a_body_says_so_rather_than_rendering_blank(): void
    {
        $message = $this->store($this->workspace, 'm1');

        $this->actingAs($this->user)->get("/threads/{$message->thread_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('open.messages.0.has_body', false)
                ->where('open.messages.0.body_html', ''));
    }

    public function test_inline_attachments_are_not_listed_as_files(): void
    {
        $message = $this->store($this->workspace, 'm1', [
            'attachments' => [
                new RemoteAttachment('invoice.pdf', 'a1', 'application/pdf', 2048),
                new RemoteAttachment('sig.png', 'a2', 'image/png', 64, isInline: true, contentId: 'sig'),
            ],
        ]);

        $this->actingAs($this->user)->get("/threads/{$message->thread_id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('open.messages.0.attachments', 1)
                ->where('open.messages.0.attachments.0.filename', 'invoice.pdf'));
    }

    // ---- flags ------------------------------------------------------------

    public function test_marking_read_updates_locally_and_queues_the_push(): void
    {
        Queue::fake();
        $message = $this->store($this->workspace, 'm1', ['isRead' => false]);

        $this->actingAs($this->user)
            ->patch("/messages/{$message->id}/flags", ['is_read' => true])
            ->assertRedirect();

        $this->assertTrue($message->fresh()->is_read);
        $this->assertSame(0, $message->thread->fresh()->unread_count);

        Queue::assertPushed(PushFlagsJob::class, fn (PushFlagsJob $job) => $job->change->isRead === true
            && $job->providerMessageIds === ['m1']
            // The pre-change value travels with the job so a failed push can put it back.
            && $job->previous['m1']['is_read'] === false);
    }

    public function test_starring_updates_the_thread_flag(): void
    {
        Queue::fake();
        $message = $this->store($this->workspace, 'm1');

        $this->actingAs($this->user)->patch("/messages/{$message->id}/flags", ['is_starred' => true]);

        $this->assertTrue($message->fresh()->is_starred);
        $this->assertTrue($message->thread->fresh()->is_starred);
    }

    public function test_an_empty_flag_request_queues_nothing(): void
    {
        Queue::fake();
        $message = $this->store($this->workspace, 'm1');

        $this->actingAs($this->user)->patch("/messages/{$message->id}/flags", []);

        Queue::assertNothingPushed();
    }

    // ---- accounts page ----------------------------------------------------

    public function test_the_accounts_page_lists_the_provider_options(): void
    {
        $this->actingAs($this->user)->get('/accounts')->assertInertia(fn (Assert $page) => $page
            ->component('Accounts/Index')
            ->has('providers', 3));
    }

    public function test_folders_are_scoped_per_account(): void
    {
        $this->assertSame(2, Folder::where('mail_account_id', $this->workspace->id)->count());
        $this->assertSame(4, Folder::count());
    }
}

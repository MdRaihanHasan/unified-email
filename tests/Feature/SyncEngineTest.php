<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\FolderRole;
use App\Jobs\BackfillJob;
use App\Jobs\FullResyncJob;
use App\Jobs\PushFlagsJob;
use App\Jobs\SyncAccountJob;
use App\Mail\Data\Address;
use App\Mail\Data\ChangeSet;
use App\Mail\Data\FlagChange;
use App\Mail\Data\MessagePage;
use App\Mail\Data\MessageUpdate;
use App\Mail\Data\RemoteFolder;
use App\Mail\Data\RemoteMessage;
use App\Mail\Data\SyncCursor;
use App\Mail\Exceptions\AuthenticationFailedException;
use App\Mail\Support\MessageWriter;
use App\Models\Folder;
use App\Models\MailAccount;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\UsesFakeProvider;
use Tests\TestCase;

class SyncEngineTest extends TestCase
{
    use RefreshDatabase, UsesFakeProvider;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeProvider();
        $this->account = MailAccount::factory()->gmailApi()->create([
            'sync_cursor' => ['token' => 'saved'],
        ]);
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

    private function runJob(object $job): void
    {
        $job->handle(app(MessageWriter::class));
    }

    // ---- incremental sync -------------------------------------------------

    public function test_an_incremental_pass_stores_changes_and_advances_the_cursor(): void
    {
        $this->provider->changeSets = [new ChangeSet(
            created: [$this->remote('a')],
            cursor: new SyncCursor(['token' => 'advanced']),
        )];

        $this->runJob(new SyncAccountJob($this->account));

        $this->assertSame(1, Message::count());
        $this->assertSame(['token' => 'advanced'], $this->account->fresh()->sync_cursor);
        $this->assertNotNull($this->account->fresh()->last_synced_at);
    }

    public function test_an_expired_cursor_triggers_a_full_resync_and_does_not_advance(): void
    {
        // The failure this guards against is silent: without the fallback the account
        // simply stops receiving mail, with no error anywhere.
        Queue::fake();
        $this->provider->cursorExpired = true;

        $this->runJob(new SyncAccountJob($this->account));

        Queue::assertPushed(FullResyncJob::class);
        $this->assertSame(['token' => 'saved'], $this->account->fresh()->sync_cursor);
    }

    public function test_rejected_credentials_move_the_account_to_auth_error(): void
    {
        Queue::fake();
        $this->provider->changesFailure = new AuthenticationFailedException('token revoked');

        $this->runJob(new SyncAccountJob($this->account));

        $account = $this->account->fresh();
        $this->assertSame(AccountStatus::AuthError, $account->status);
        $this->assertSame('token revoked', $account->last_error);
        Queue::assertNotPushed(FullResyncJob::class, 'a revoked credential is not a stale cursor');
    }

    public function test_rejected_credentials_during_a_backfill_also_mark_the_account(): void
    {
        Queue::fake();
        $this->account->update(['backfill_done_at' => null, 'sync_cursor' => null]);
        $this->provider->foldersFailure = new AuthenticationFailedException('app password revoked');

        $this->runJob(new BackfillJob($this->account));

        $this->assertSame(AccountStatus::AuthError, $this->account->fresh()->status);
    }

    public function test_an_account_without_a_backfill_is_sent_to_backfill_first(): void
    {
        Queue::fake();
        $this->account->update(['backfill_done_at' => null]);

        $this->runJob(new SyncAccountJob($this->account));

        Queue::assertPushed(BackfillJob::class);
    }

    public function test_an_account_without_a_cursor_is_sent_to_full_resync(): void
    {
        Queue::fake();
        $this->account->update(['sync_cursor' => null]);

        $this->runJob(new SyncAccountJob($this->account));

        Queue::assertPushed(FullResyncJob::class);
    }

    public function test_a_disabled_account_is_left_alone(): void
    {
        $this->account->update(['status' => AccountStatus::Disabled]);
        $this->provider->changeSets = [new ChangeSet(created: [$this->remote('a')])];

        $this->runJob(new SyncAccountJob($this->account));

        $this->assertSame(0, Message::count());
    }

    public function test_flag_updates_from_the_provider_are_applied(): void
    {
        app(MessageWriter::class)->store($this->account, $this->remote('a', ['isRead' => false]));

        $this->provider->changeSets = [new ChangeSet(updated: [new MessageUpdate('a', isRead: true)])];

        $this->runJob(new SyncAccountJob($this->account));

        $this->assertTrue(Message::first()->is_read);
    }

    // ---- backfill ---------------------------------------------------------

    public function test_backfill_records_folders_and_captures_the_cursor_before_reading(): void
    {
        // Taking the cursor afterwards would lose every change made during a long walk.
        Queue::fake();
        $this->account->update(['backfill_done_at' => null, 'sync_cursor' => null]);
        $this->provider->cursor = new SyncCursor(['token' => 'before-backfill']);
        $this->provider->folders = [new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox)];
        $this->provider->pages = ['INBOX' => [new MessagePage([$this->remote('a')])]];

        $this->runJob(new BackfillJob($this->account));

        $this->assertSame(['token' => 'before-backfill'], $this->account->fresh()->sync_cursor);
        $this->assertSame(1, Folder::count());
        $this->assertSame(1, Message::count());
    }

    public function test_backfill_walks_pages_across_dispatches_and_then_finishes(): void
    {
        Queue::fake();
        $this->account->update(['backfill_done_at' => null, 'sync_cursor' => null]);
        $this->provider->folders = [new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox)];
        $this->provider->pages = ['INBOX' => [
            new MessagePage([$this->remote('a')], nextCursor: 'page-2'),
            new MessagePage([$this->remote('b')]),
        ]];

        $this->runJob(new BackfillJob($this->account));
        $this->assertSame('page-2', Folder::first()->backfill_cursor, 'progress is stored so a restart resumes');
        $this->assertNull($this->account->fresh()->backfill_done_at);

        $this->runJob(new BackfillJob($this->account));
        $this->assertNull(Folder::first()->backfill_cursor);
        $this->assertNotNull(Folder::first()->backfill_done_at);

        // One more pass: no folders left, so the account is marked complete.
        $this->runJob(new BackfillJob($this->account));
        $this->assertNotNull($this->account->fresh()->backfill_done_at);
        $this->assertSame(2, Message::count());

        // Two passes did work and queued a successor; the third found nothing left
        // to walk and finished instead of queueing again.
        Queue::assertPushed(BackfillJob::class, 2);
    }

    public function test_backfill_skips_gmail_all_mail(): void
    {
        // All Mail mirrors every other folder, so walking it doubles the entire job.
        Queue::fake();
        $this->account->update(['backfill_done_at' => null, 'sync_cursor' => null]);
        $this->provider->folders = [
            new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox),
            new RemoteFolder('[Gmail]/All Mail', 'All Mail', FolderRole::AllMail),
            new RemoteFolder('[Gmail]/Trash', 'Trash', FolderRole::Trash),
        ];
        $this->provider->pages = [
            'INBOX' => [new MessagePage([$this->remote('a')])],
            '[Gmail]/All Mail' => [new MessagePage([$this->remote('dup')])],
            '[Gmail]/Trash' => [new MessagePage([$this->remote('trash')])],
        ];

        // Inbox page, then the completion pass.
        $this->runJob(new BackfillJob($this->account));
        $this->runJob(new BackfillJob($this->account));

        $this->assertSame(3, Folder::count(), 'all folders are still recorded');
        $this->assertSame(['a'], Message::pluck('provider_message_id')->all());
        $this->assertNotNull($this->account->fresh()->backfill_done_at);
    }

    public function test_backfill_starts_with_the_inbox(): void
    {
        Queue::fake();
        $this->account->update(['backfill_done_at' => null, 'sync_cursor' => null]);
        $this->provider->folders = [
            new RemoteFolder('Archive', 'Archive', FolderRole::Archive),
            new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox),
        ];
        $this->provider->pages = [
            'Archive' => [new MessagePage([$this->remote('archived')])],
            'INBOX' => [new MessagePage([$this->remote('inboxed')])],
        ];

        $this->runJob(new BackfillJob($this->account));

        $this->assertSame(['inboxed'], Message::pluck('provider_message_id')->all());
    }

    public function test_backfill_does_nothing_once_it_has_completed(): void
    {
        Queue::fake();
        $this->provider->folders = [new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox)];

        $this->runJob(new BackfillJob($this->account)); // backfill_done_at already set

        $this->assertSame(0, Folder::count());
        Queue::assertNotPushed(BackfillJob::class);
    }

    // ---- full resync ------------------------------------------------------

    public function test_full_resync_clears_folder_progress_and_keeps_stored_mail(): void
    {
        // The re-walk is an upsert, so the inbox must not empty out while recovering.
        Queue::fake();
        app(MessageWriter::class)->store($this->account, $this->remote('a'));
        $folder = Folder::create([
            'mail_account_id' => $this->account->id, 'remote_id' => 'INBOX',
            'name' => 'Inbox', 'role' => FolderRole::Inbox,
            'backfill_cursor' => 'page-7', 'backfill_done_at' => now(),
        ]);

        (new FullResyncJob($this->account))->handle();

        $folder->refresh();
        $this->assertNull($folder->backfill_cursor);
        $this->assertNull($folder->backfill_done_at);
        $this->assertNull($this->account->fresh()->sync_cursor);
        $this->assertNull($this->account->fresh()->backfill_done_at);
        $this->assertSame(1, Message::count(), 'stored mail survives a resync');

        Queue::assertPushed(BackfillJob::class);
    }

    // ---- flag pushes ------------------------------------------------------

    public function test_a_flag_push_reaches_the_provider(): void
    {
        (new PushFlagsJob($this->account, ['a', 'b'], new FlagChange(isRead: true)))->handle();

        $this->assertCount(1, $this->provider->appliedFlags);
        $this->assertSame(['a', 'b'], $this->provider->appliedFlags[0]['ids']);
    }

    public function test_an_empty_flag_change_is_not_sent(): void
    {
        (new PushFlagsJob($this->account, ['a'], new FlagChange))->handle();

        $this->assertSame([], $this->provider->appliedFlags);
    }

    public function test_a_failed_flag_push_puts_the_local_flag_back(): void
    {
        // Otherwise our state and the provider's drift apart with nothing to
        // reconcile them: the provider never changed, so no delta will correct it.
        $message = app(MessageWriter::class)->store($this->account, $this->remote('a', ['isRead' => false]));
        $message->update(['is_read' => true]); // optimistic local write

        $job = new PushFlagsJob(
            $this->account,
            ['a'],
            new FlagChange(isRead: true),
            previous: ['a' => ['is_read' => false]],
        );

        $job->failed(new \RuntimeException('provider rejected the change'));

        $this->assertFalse($message->fresh()->is_read);
        $this->assertSame(1, $message->thread->fresh()->unread_count, 'thread counts follow the revert');
    }

    public function test_a_flag_push_failing_on_auth_marks_the_account(): void
    {
        (new PushFlagsJob($this->account, ['a'], new FlagChange(isRead: true)))
            ->failed(new AuthenticationFailedException('app password revoked'));

        $this->assertSame(AccountStatus::AuthError, $this->account->fresh()->status);
    }
}

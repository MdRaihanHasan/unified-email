<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\FolderRole;
use App\Mail\Data\Address;
use App\Mail\Data\RemoteFolder;
use App\Mail\Data\RemoteMessage;
use App\Mail\Support\MessageWriter;
use App\Models\Folder;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\UsesFakeProvider;
use Tests\TestCase;

class SyncStatusTest extends TestCase
{
    use RefreshDatabase, UsesFakeProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeProvider();
    }

    private function account(array $overrides = []): MailAccount
    {
        return MailAccount::factory()->gmailApi()->create([
            'status' => AccountStatus::Active,
            ...$overrides,
        ]);
    }

    // ---- staleness --------------------------------------------------------

    public function test_a_mailbox_still_doing_its_first_import_is_not_reported_as_behind(): void
    {
        // This produced two banners that contradicted each other on a mailbox that
        // had just been connected: "still importing" beside "last synced never".
        $account = $this->account(['backfill_done_at' => null, 'last_synced_at' => null]);

        $this->assertFalse($account->isStale());
    }

    public function test_a_finished_mailbox_that_has_never_synced_is_behind(): void
    {
        $account = $this->account(['backfill_done_at' => now(), 'last_synced_at' => null]);

        $this->assertTrue($account->isStale());
    }

    public function test_a_finished_mailbox_that_synced_recently_is_fine(): void
    {
        $account = $this->account(['backfill_done_at' => now(), 'last_synced_at' => now()->subMinutes(2)]);

        $this->assertFalse($account->isStale());
    }

    public function test_a_finished_mailbox_that_stopped_syncing_is_behind(): void
    {
        $account = $this->account(['backfill_done_at' => now(), 'last_synced_at' => now()->subHours(4)]);

        $this->assertTrue($account->isStale());
    }

    public function test_a_disabled_mailbox_is_never_reported_as_behind(): void
    {
        $account = $this->account([
            'status' => AccountStatus::Disabled,
            'backfill_done_at' => now(),
            'last_synced_at' => null,
        ]);

        $this->assertFalse($account->isStale());
    }

    // ---- a job nobody is running -----------------------------------------

    public function test_a_freshly_connected_mailbox_is_not_yet_called_stalled(): void
    {
        // A worker gets a few minutes before we accuse it of being absent.
        $account = $this->account(['backfill_done_at' => null, 'created_at' => now()]);

        $this->assertFalse($account->importStalled());
    }

    public function test_a_mailbox_with_no_folders_after_a_while_is_stalled(): void
    {
        // The first thing BackfillJob does is record folders, so their absence means
        // the job never ran — the queue worker is not up.
        $account = $this->account(['backfill_done_at' => null, 'created_at' => now()->subMinutes(30)]);

        $this->assertTrue($account->importStalled());
        $this->assertFalse($account->hasStartedImport());
    }

    public function test_a_mailbox_that_has_recorded_folders_is_progressing_not_stalled(): void
    {
        $account = $this->account(['backfill_done_at' => null, 'created_at' => now()->subMinutes(30)]);
        app(MessageWriter::class)->storeFolders($account, [
            new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox),
        ]);

        $this->assertTrue($account->hasStartedImport());
        $this->assertFalse($account->importStalled());
    }

    public function test_a_finished_mailbox_is_never_stalled(): void
    {
        $account = $this->account(['backfill_done_at' => now(), 'created_at' => now()->subYear()]);

        $this->assertFalse($account->importStalled());
    }

    // ---- progress ---------------------------------------------------------

    public function test_progress_counts_only_the_folders_that_will_be_walked(): void
    {
        // Counting the ones deliberately skipped makes the total look wrong and the
        // progress look stuck short of the end.
        $account = $this->account(['backfill_done_at' => null]);

        app(MessageWriter::class)->storeFolders($account, [
            new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox),
            new RemoteFolder('SENT', 'Sent', FolderRole::Sent),
            new RemoteFolder('TRASH', 'Trash', FolderRole::Trash),
            new RemoteFolder('SPAM', 'Spam', FolderRole::Junk),
            new RemoteFolder('UNREAD', 'Unread', isSelectable: false),
        ]);

        Folder::where('remote_id', 'INBOX')->update(['backfill_done_at' => now()]);

        $progress = $account->importProgress();

        $this->assertSame(4, $progress['folders_total'], 'trash and spam are walked now; only the flag label is skipped');
        $this->assertSame(1, $progress['folders_done']);
        $this->assertSame(0, $progress['messages']);
    }

    public function test_progress_counts_imported_messages(): void
    {
        $account = $this->account(['backfill_done_at' => null]);
        $writer = app(MessageWriter::class);

        foreach (['a', 'b', 'c'] as $id) {
            $writer->store($account, new RemoteMessage(
                providerMessageId: $id,
                rfc822MessageId: "<{$id}@x>",
                from: new Address('sender@example.com'),
                subject: "Message {$id}",
                receivedAt: new \DateTimeImmutable('2026-08-01 09:00:00'),
            ));
        }

        $this->assertSame(3, $account->importProgress()['messages']);
    }

    // ---- what the UI is told ---------------------------------------------

    public function test_the_layout_is_told_which_mailbox_is_stalled_and_how_far_it_got(): void
    {
        $user = User::factory()->create();

        $importing = $this->account([
            'email' => 'importing@gmail.com',
            'backfill_done_at' => null,
            'created_at' => now(),
        ]);
        app(MessageWriter::class)->storeFolders($importing, [
            new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox),
        ]);

        $this->account([
            'email' => 'stalled@gmail.com',
            'backfill_done_at' => null,
            'created_at' => now()->subHour(),
        ]);

        $this->actingAs($user)->get('/inbox')->assertInertia(fn (Assert $page) => $page
            ->where('accounts.0.email', 'importing@gmail.com')
            ->where('accounts.0.import_stalled', false)
            ->where('accounts.0.is_stale', false)
            ->where('accounts.0.import_progress.folders_total', 1)
            ->where('accounts.1.email', 'stalled@gmail.com')
            ->where('accounts.1.import_stalled', true));
    }

    public function test_a_settled_mailbox_carries_no_progress_payload(): void
    {
        // Two extra queries per account, pointless once the mailbox is filled.
        $user = User::factory()->create();
        $this->account(['backfill_done_at' => now(), 'last_synced_at' => now()]);

        $this->actingAs($user)->get('/inbox')->assertInertia(fn (Assert $page) => $page
            ->where('accounts.0.import_progress', null)
            ->where('accounts.0.backfilling', false));
    }

    // ---- the diagnostic command ------------------------------------------

    public function test_the_status_command_reports_a_stalled_import(): void
    {
        $this->account(['email' => 'stuck@gmail.com', 'backfill_done_at' => null, 'created_at' => now()->subHour()]);

        $this->artisan('mail:status')
            ->expectsOutputToContain('stuck@gmail.com')
            ->expectsOutputToContain('not started')
            ->expectsOutputToContain('docker compose ps worker')
            ->assertSuccessful();
    }

    public function test_the_status_command_says_so_when_nothing_is_connected(): void
    {
        $this->artisan('mail:status')->assertSuccessful();
    }
}

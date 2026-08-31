<?php

namespace Tests\Feature;

use App\Enums\FolderRole;
use App\Jobs\BackfillJob;
use App\Jobs\SyncAccountJob;
use App\Mail\Data\Address;
use App\Mail\Data\ChangeSet;
use App\Mail\Data\MessageUpdate;
use App\Mail\Data\RemoteMessage;
use App\Mail\Support\MessageWriter;
use App\Models\Folder;
use App\Models\MailAccount;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\UsesFakeProvider;
use Tests\TestCase;

/**
 * Regression tests from the 2026-08-31 audit. Each one encodes behaviour a mail
 * client cannot function without; each failed on the code it was written against,
 * pinning the three highest-impact sync/threading defects the audit found. They
 * guard the fixes now — if one of these goes red again, mail is being lost or
 * misfiled, not merely mis-rendered.
 */
class AuditFindingsTest extends TestCase
{
    use RefreshDatabase, UsesFakeProvider;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeProvider();
        $this->account = MailAccount::factory()->gmailApi()->create([
            'sync_cursor' => ['historyId' => '111'],
            'backfill_done_at' => now(),
            'last_synced_at' => now(),
        ]);
    }

    private function inboxFolder(): Folder
    {
        return Folder::create([
            'mail_account_id' => $this->account->id,
            'remote_id' => 'INBOX',
            'name' => 'Inbox',
            'path' => 'Inbox',
            'role' => FolderRole::Inbox,
            'is_label' => true,
            'is_selectable' => true,
            'backfill_done_at' => now(),
        ]);
    }

    private function remote(string $id, array $overrides = []): RemoteMessage
    {
        return new RemoteMessage(...[
            'providerMessageId' => $id,
            'rfc822MessageId' => "<{$id}@example.com>",
            'from' => new Address('sender@example.com', 'Sender'),
            'to' => [new Address('me@example.com')],
            'subject' => 'Payment reminder',
            'receivedAt' => new \DateTimeImmutable('2026-08-01 09:00:00'),
            'folderRemoteIds' => ['INBOX'],
            ...$overrides,
        ]);
    }

    /**
     * FINDING 1 (P0): after a cursor-expiry full resync, no fresh cursor is ever
     * captured — BackfillJob only takes one when the account has NO folders, and a
     * resync keeps its folders. The next scheduler tick sees an empty cursor and
     * dispatches FullResyncJob again: an infinite full-mailbox rewalk loop.
     */
    public function test_cursor_expiry_recovery_leaves_the_account_with_a_usable_cursor(): void
    {
        $this->inboxFolder();
        $this->provider->cursorExpired = true;

        // QUEUE_CONNECTION=sync runs FullResyncJob inline; the backfill chain
        // self-dispatches, which the overlap lock stops when nested inline, so we
        // drain it manually — one handle() per queued hop, as the worker would.
        (new SyncAccountJob($this->account))->handle(app(MessageWriter::class));

        for ($hops = 0; $hops < 10 && ! $this->account->fresh()->hasFinishedBackfill(); $hops++) {
            (new BackfillJob($this->account))->handle(app(MessageWriter::class));
        }

        $account = $this->account->fresh();

        $this->assertTrue($account->hasFinishedBackfill(), 'the recovery re-walk should complete');
        $this->assertNotEmpty(
            $account->sync_cursor,
            'recovery must capture a fresh cursor, or every following tick full-resyncs the mailbox forever',
        );
    }

    /**
     * FINDING 2 (P0): archiving in Gmail removes the message's last tracked folder
     * label, so the provider reports an EMPTY folder list. MessageWriter::syncFolders
     * early-returns on [], the stale INBOX pivot row survives, and the thread never
     * leaves the app's inbox.
     */
    public function test_removing_the_last_folder_label_detaches_the_message_from_the_folder(): void
    {
        $this->inboxFolder();
        $writer = app(MessageWriter::class);

        $message = $writer->store($this->account, $this->remote('m-1'));
        $this->assertSame(1, $message->folders()->count(), 'precondition: message is linked to INBOX');

        // Archive in Gmail: history reports the change, flagsOf() re-reads the
        // message, and folderLabels() of an archived message with only category
        // labels left is [].
        $writer->applyChangeSet($this->account, new ChangeSet(
            updated: [new MessageUpdate(providerMessageId: 'm-1', folderRemoteIds: [])],
        ));

        $this->assertSame(
            0,
            $message->fresh()->folders()->count(),
            'an archived message must leave the inbox: an empty folder list is a real state, not "unknown"',
        );
    }

    /**
     * FINDING 3 (HIGH): tier 3 threads by normalised subject + participant overlap
     * even when Gmail itself says the messages belong to DIFFERENT threads. Every
     * recurring automated notification ("Payment reminder", "Security alert") from
     * one sender collapses into a single giant local thread — observed live as a
     * 144-message "Application stuck in the queue" thread.
     */
    public function test_messages_gmail_keeps_in_separate_threads_stay_separate(): void
    {
        $writer = app(MessageWriter::class);

        $first = $writer->store($this->account, $this->remote('n-1', [
            'providerThreadId' => 'gmail-thread-1',
        ]));

        $second = $writer->store($this->account, $this->remote('n-2', [
            'providerThreadId' => 'gmail-thread-2', // Gmail: a different conversation
            'receivedAt' => new \DateTimeImmutable('2026-08-03 09:00:00'),
        ]));

        $this->assertNotSame(
            $first->fresh()->thread_id,
            $second->fresh()->thread_id,
            'same subject + same sender must not override the provider\'s own threading',
        );
    }
}

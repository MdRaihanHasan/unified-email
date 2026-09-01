<?php

namespace Tests\Feature;

use App\Enums\FolderRole;
use App\Mail\Data\Address;
use App\Mail\Data\ChangeSet;
use App\Mail\Data\MessageUpdate;
use App\Mail\Data\RemoteAttachment;
use App\Mail\Data\RemoteFolder;
use App\Mail\Data\RemoteMessage;
use App\Mail\Support\MessageWriter;
use App\Models\Folder;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\Thread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageWriterTest extends TestCase
{
    use RefreshDatabase;

    private MessageWriter $writer;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(MessageWriter::class);
        $this->account = MailAccount::factory()->gmailApi()->create();
    }

    private function remote(array $overrides = []): RemoteMessage
    {
        return new RemoteMessage(...[
            'providerMessageId' => 'm1',
            'rfc822MessageId' => '<m1@example.com>',
            'from' => new Address('sender@example.com', 'Sender'),
            'to' => [new Address('me@company.com')],
            'subject' => 'Invoice 42',
            'snippet' => 'Please find attached',
            'receivedAt' => new \DateTimeImmutable('2026-08-01 09:00:00'),
            ...$overrides,
        ]);
    }

    public function test_storing_a_message_creates_its_thread_and_denormalised_counts(): void
    {
        $message = $this->writer->store($this->account, $this->remote(['isRead' => false]));

        $this->assertSame('Invoice 42', $message->subject);

        $thread = $message->thread;
        $this->assertSame(1, $thread->message_count);
        $this->assertSame(1, $thread->unread_count);
        $this->assertContains('sender@example.com', $thread->participants);
        $this->assertContains('me@company.com', $thread->participants);
    }

    public function test_storing_the_same_provider_message_twice_updates_rather_than_duplicates(): void
    {
        // Retried jobs and full resyncs both re-present messages we already hold.
        $this->writer->store($this->account, $this->remote(['subject' => 'First']));
        $this->writer->store($this->account, $this->remote(['subject' => 'Corrected']));

        $this->assertSame(1, Message::count());
        $this->assertSame('Corrected', Message::first()->subject);
        $this->assertSame(1, Thread::count());
    }

    public function test_a_headers_only_resync_does_not_wipe_an_already_fetched_body(): void
    {
        // Bodies are fetched separately, so most sync passes carry null for them.
        $this->writer->store($this->account, $this->remote([
            'bodyHtml' => '<p>Hello</p>',
            'bodyText' => 'Hello',
        ]));

        $this->writer->store($this->account, $this->remote());

        $message = Message::first();
        $this->assertSame('<p>Hello</p>', $message->body_html);
        $this->assertSame('Hello', $message->body_text);
    }

    public function test_a_resync_keeps_the_thread_the_message_was_already_filed_under(): void
    {
        // Re-resolving could move the message after a sibling arrived and changed what
        // the heuristics would now pick, quietly splitting the conversation.
        $message = $this->writer->store($this->account, $this->remote());
        $originalThreadId = $message->thread_id;

        Thread::factory()->create(['subject_normalized' => 'invoice 42']);

        $this->writer->store($this->account, $this->remote());

        $this->assertSame($originalThreadId, Message::first()->thread_id);
    }

    public function test_a_message_is_filed_into_every_label_the_provider_reports(): void
    {
        $this->writer->storeFolders($this->account, [
            new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox, isLabel: true),
            new RemoteFolder('Label_1', 'Receipts', isLabel: true),
        ]);

        $message = $this->writer->store($this->account, $this->remote([
            'folderRemoteIds' => ['INBOX', 'Label_1'],
        ]));

        $this->assertCount(2, $message->folders);
    }

    public function test_dropping_a_gmail_label_removes_the_folder_link(): void
    {
        // Gmail reports a removed label by simply listing fewer of them; the message
        // id never changes. sync() is what makes that removal visible.
        $this->writer->storeFolders($this->account, [
            new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox, isLabel: true),
            new RemoteFolder('Label_1', 'Receipts', isLabel: true),
        ]);

        $this->writer->store($this->account, $this->remote(['folderRemoteIds' => ['INBOX', 'Label_1']]));
        $this->writer->store($this->account, $this->remote(['folderRemoteIds' => ['INBOX']]));

        $message = Message::first();
        $this->assertCount(1, $message->folders);
        $this->assertSame('INBOX', $message->folders->first()->remote_id);
    }

    public function test_a_partial_update_leaves_the_flags_the_provider_did_not_mention(): void
    {
        $this->writer->store($this->account, $this->remote(['isRead' => false, 'isStarred' => true]));

        $this->writer->applyUpdate($this->account, new MessageUpdate('m1', isRead: true));

        $message = Message::first();
        $this->assertTrue($message->is_read);
        $this->assertTrue($message->is_starred, 'starred was not mentioned, so it must not change');
    }

    public function test_an_update_for_an_unknown_message_is_ignored_quietly(): void
    {
        // Normal: the message may predate the backfill window.
        $this->assertNull($this->writer->applyUpdate($this->account, new MessageUpdate('never-seen', isRead: true)));
    }

    public function test_deleting_the_last_message_removes_its_thread(): void
    {
        $this->writer->store($this->account, $this->remote());

        $this->writer->applyChangeSet($this->account, new ChangeSet(deletedIds: ['m1']));

        $this->assertSame(0, Message::count());
        $this->assertSame(0, Thread::count());
    }

    public function test_deleting_one_message_of_a_thread_recounts_the_rest(): void
    {
        $this->writer->store($this->account, $this->remote(['providerMessageId' => 'm1', 'rfc822MessageId' => '<m1@x>']));
        $this->writer->store($this->account, $this->remote([
            'providerMessageId' => 'm2',
            'rfc822MessageId' => '<m2@x>',
            'inReplyTo' => '<m1@x>',
            'isRead' => true,
        ]));

        $this->assertSame(2, Thread::first()->message_count);

        $this->writer->applyChangeSet($this->account, new ChangeSet(deletedIds: ['m2']));

        $thread = Thread::first();
        $this->assertSame(1, $thread->message_count);
        $this->assertSame(1, $thread->unread_count);
    }

    public function test_thread_counts_are_derived_rather_than_incremented(): void
    {
        // Applying the same change set twice must not double any counter — an
        // incremented one drifts the first time a job retries.
        $changes = new ChangeSet(created: [
            $this->remote(['providerMessageId' => 'a', 'rfc822MessageId' => '<a@x>']),
            $this->remote(['providerMessageId' => 'b', 'rfc822MessageId' => '<b@x>', 'inReplyTo' => '<a@x>']),
        ]);

        $this->writer->applyChangeSet($this->account, $changes);
        $this->writer->applyChangeSet($this->account, $changes);

        $this->assertSame(2, Thread::first()->message_count);
        $this->assertSame(2, Message::count());
    }

    public function test_attachments_are_recorded_and_inline_parts_do_not_count(): void
    {
        $message = $this->writer->store($this->account, $this->remote([
            'attachments' => [
                new RemoteAttachment('invoice.pdf', 'att-1', 'application/pdf', 1024),
                new RemoteAttachment('logo.png', 'att-2', 'image/png', 512, isInline: true, contentId: 'logo'),
            ],
        ]));

        $this->assertCount(2, $message->attachments);
        $this->assertTrue($message->has_attachments, 'one real attachment is present');

        $inlineOnly = $this->writer->store($this->account, $this->remote([
            'providerMessageId' => 'inline-only',
            'rfc822MessageId' => '<inline@x>',
            'attachments' => [new RemoteAttachment('sig.png', 'att-3', 'image/png', 10, isInline: true, contentId: 'sig')],
        ]));

        $this->assertFalse($inlineOnly->has_attachments, 'a signature image is not an attachment');
    }

    public function test_re_storing_does_not_duplicate_attachment_rows(): void
    {
        $remote = $this->remote(['attachments' => [new RemoteAttachment('invoice.pdf', 'att-1', 'application/pdf', 1024)]]);

        $this->writer->store($this->account, $remote);
        $this->writer->store($this->account, $remote);

        $this->assertSame(1, Message::first()->attachments()->count());
    }

    public function test_storing_folders_is_idempotent_and_updates_counts(): void
    {
        $this->writer->storeFolders($this->account, [new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox, unreadCount: 3)]);
        $this->writer->storeFolders($this->account, [new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox, unreadCount: 7)]);

        $this->assertSame(1, Folder::count());
        $this->assertSame(7, Folder::first()->unread_count);
    }

    public function test_a_change_set_reports_what_it_applied(): void
    {
        $this->writer->store($this->account, $this->remote(['providerMessageId' => 'old', 'rfc822MessageId' => '<old@x>']));

        $applied = $this->writer->applyChangeSet($this->account, new ChangeSet(
            created: [$this->remote(['providerMessageId' => 'new', 'rfc822MessageId' => '<new@x>'])],
            updated: [new MessageUpdate('old', isRead: true)],
            deletedIds: ['missing'],
        ));

        $this->assertSame(['created' => 1, 'updated' => 1, 'deleted' => 0], $applied);
    }

    public function test_two_attachments_with_the_same_filename_stay_two_rows(): void
    {
        // Keyed on the provider's attachment id now: "image.png" twice with no
        // content-id used to collapse into one row.
        $message = $this->writer->store($this->account, $this->remote([
            'attachments' => [
                new RemoteAttachment(filename: 'image.png', remoteId: 'att-1'),
                new RemoteAttachment(filename: 'image.png', remoteId: 'att-2'),
            ],
        ]));

        $this->assertSame(2, $message->attachments()->count());
    }

    public function test_a_resync_prunes_attachments_the_provider_no_longer_reports(): void
    {
        $this->writer->store($this->account, $this->remote([
            'attachments' => [new RemoteAttachment(filename: 'old.pdf', remoteId: 'att-1')],
        ]));

        $message = $this->writer->store($this->account, $this->remote([
            'attachments' => [new RemoteAttachment(filename: 'new.pdf', remoteId: 'att-2')],
        ]));

        $this->assertSame(['new.pdf'], $message->attachments()->pluck('filename')->all());
    }
}

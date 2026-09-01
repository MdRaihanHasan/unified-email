<?php

namespace Tests\Feature;

use App\Enums\FolderRole;
use App\Mail\Data\Address;
use App\Mail\Data\RemoteFolder;
use App\Mail\Data\RemoteMessage;
use App\Mail\Support\MessageWriter;
use App\Models\Folder;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpamTrashViewTest extends TestCase
{
    use RefreshDatabase;

    private MessageWriter $writer;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(MessageWriter::class);
        $this->account = MailAccount::factory()->gmailApi()->create();

        $this->writer->storeFolders($this->account, [
            new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox, isLabel: true),
            new RemoteFolder('SPAM', 'Spam', FolderRole::Junk, isLabel: true),
            new RemoteFolder('TRASH', 'Trash', FolderRole::Trash, isLabel: true),
        ]);
    }

    private function store(string $id, string $folder, array $overrides = []): Message
    {
        return $this->writer->store($this->account, new RemoteMessage(...[
            'providerMessageId' => $id,
            'rfc822MessageId' => "<{$id}@example.com>",
            'from' => new Address('sender@example.com'),
            'subject' => "Message {$id}",
            'receivedAt' => new \DateTimeImmutable('2026-08-01 09:00:00'),
            'isRead' => false,
            'folderRemoteIds' => [$folder],
            ...$overrides,
        ]));
    }

    public function test_spam_and_trash_folders_are_walked_by_backfill_now(): void
    {
        $roles = Folder::all()->filter(fn (Folder $f) => $f->shouldBackfill())->pluck('role');

        $this->assertTrue($roles->contains(FolderRole::Junk));
        $this->assertTrue($roles->contains(FolderRole::Trash));
    }

    public function test_unread_spam_does_not_count_toward_unread(): void
    {
        $junk = $this->store('m1', 'SPAM');
        $inbox = $this->store('m2', 'INBOX');

        $this->assertSame(0, $junk->thread->fresh()->unread_count);
        $this->assertSame(1, $inbox->thread->fresh()->unread_count);
    }

    public function test_the_junk_and_trash_views_list_their_threads_and_all_mail_excludes_them(): void
    {
        $junk = $this->store('m1', 'SPAM');
        $trash = $this->store('m2', 'TRASH');
        $inbox = $this->store('m3', 'INBOX');

        $this->assertSame([$junk->thread_id], Thread::inView('junk')->pluck('id')->all());
        $this->assertSame([$trash->thread_id], Thread::inView('trash')->pluck('id')->all());
        $this->assertSame([$inbox->thread_id], Thread::inView('all')->pluck('id')->all());
    }

    public function test_an_archived_message_with_no_folders_still_appears_in_all_mail(): void
    {
        $message = $this->store('m1', 'INBOX');
        $this->writer->store($this->account, new RemoteMessage(
            providerMessageId: 'm1',
            rfc822MessageId: '<m1@example.com>',
            from: new Address('sender@example.com'),
            folderRemoteIds: [], // archived: the empty set is a real state
        ));

        $this->assertContains($message->thread_id, Thread::inView('all')->pluck('id')->all());
        $this->assertNotContains($message->thread_id, Thread::inView('inbox')->pluck('id')->all());
    }

    public function test_open_thread_marks_trashed_messages_so_the_ui_can_collapse_them(): void
    {
        $kept = $this->store('m1', 'INBOX');
        $this->store('m2', 'TRASH', [
            'rfc822MessageId' => '<m2@example.com>',
            'inReplyTo' => '<m1@example.com>',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get("/inbox?thread={$kept->thread_id}");

        $messages = collect($response->inertiaPage()['props']['open']['messages']);

        $this->assertSame(2, $messages->count());
        $this->assertNull($messages->firstWhere('id', $kept->id)['hidden_reason']);
        $this->assertSame('trash', $messages->last()['hidden_reason']);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\FolderRole;
use App\Mail\Data\Address;
use App\Mail\Data\RemoteFolder;
use App\Mail\Data\RemoteMessage;
use App\Mail\Support\MessageWriter;
use App\Mail\Support\SearchQueryParser;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private MessageWriter $writer;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer = app(MessageWriter::class);
        $this->account = MailAccount::factory()->gmailApi()->create(['label' => 'Bixcel']);

        $this->writer->storeFolders($this->account, [
            new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox, isLabel: true),
            new RemoteFolder('TRASH', 'Trash', FolderRole::Trash, isLabel: true),
        ]);
    }

    private function store(array $overrides = []): Message
    {
        static $n = 0;
        $n++;

        return $this->writer->store($this->account, new RemoteMessage(...[
            'providerMessageId' => "m{$n}",
            'rfc822MessageId' => "<m{$n}@example.com>",
            'from' => new Address('sender@example.com', 'Sender'),
            'to' => [new Address('me@company.com', 'Me')],
            'subject' => "Message {$n}",
            'bodyText' => 'nothing in particular',
            'receivedAt' => new \DateTimeImmutable('2026-08-10 09:00:00'),
            'folderRemoteIds' => ['INBOX'],
            ...$overrides,
        ]));
    }

    private function hits(string $query): array
    {
        return Thread::matching($query)->pluck('id')->all();
    }

    // ---- the parser ---------------------------------------------------------

    public function test_operators_parse_and_the_rest_stays_free_text(): void
    {
        $parsed = app(SearchQueryParser::class)->parse(
            'from:cloudflare is:unread has:attachment before:2026/09/01 "exact phrase" invoice',
        );

        $this->assertSame(['cloudflare'], $parsed['from']);
        $this->assertSame(['unread'], $parsed['is']);
        $this->assertTrue($parsed['has_attachment']);
        $this->assertSame('2026-09-01', $parsed['before']->format('Y-m-d'));
        $this->assertSame('"exact phrase" invoice', $parsed['text']);
    }

    public function test_an_unknown_operator_degrades_to_literal_text(): void
    {
        $parsed = app(SearchQueryParser::class)->parse('label:foo hello');

        $this->assertSame('label:foo hello', $parsed['text']);
    }

    // ---- the scope ----------------------------------------------------------

    public function test_from_matches_the_sender_not_body_words(): void
    {
        $wanted = $this->store(['from' => new Address('billing@cloudflare.com', 'Cloudflare')]);
        // The literal words "from cloudflare" in a body must NOT match from:cloudflare.
        $this->store(['bodyText' => 'I got a letter from cloudflare yesterday']);

        $this->assertSame([$wanted->thread_id], $this->hits('from:cloudflare'));
    }

    public function test_prefix_matching_finds_the_word_you_are_still_typing(): void
    {
        $wanted = $this->store(['subject' => 'Invoice 2418']);

        $this->assertSame([$wanted->thread_id], $this->hits('invoic'));
    }

    public function test_recipients_are_searchable_now(): void
    {
        $wanted = $this->store(['to' => [new Address('anna@client.test', 'Anna Bergström')]]);
        $this->store();

        $this->assertSame([$wanted->thread_id], $this->hits('to:anna'));
        $this->assertSame([$wanted->thread_id], $this->hits('anna'));
    }

    public function test_operators_must_all_hit_the_same_message(): void
    {
        $read = $this->store([
            'from' => new Address('billing@cloudflare.com'), 'isRead' => true,
        ]);
        $unreadOther = $this->store([
            'from' => new Address('other@example.com'), 'isRead' => false,
            'inReplyTo' => "<{$read->rfc822_message_id}>",
        ]);

        $this->assertSame([], $this->hits('from:cloudflare is:unread'));
        $this->assertNotSame([], $this->hits('from:cloudflare is:read'));
    }

    public function test_date_windows_bound_received_at(): void
    {
        $old = $this->store(['receivedAt' => new \DateTimeImmutable('2026-06-01')]);
        $new = $this->store(['receivedAt' => new \DateTimeImmutable('2026-08-20')]);

        $this->assertSame([$old->thread_id], $this->hits('before:2026-07-01 message'));
        $this->assertSame([$new->thread_id], $this->hits('after:2026-08-01 message'));
    }

    public function test_account_operator_narrows_by_mailbox_label(): void
    {
        $other = MailAccount::factory()->gmailApi()->create(['label' => 'Oxcel']);
        $mine = $this->store();
        $theirs = $this->writer->store($other, new RemoteMessage(
            providerMessageId: 'x1',
            rfc822MessageId: '<x1@example.com>',
            from: new Address('sender@example.com'),
            subject: 'Message elsewhere',
        ));

        $this->assertSame([$mine->thread_id], $this->hits('account:bixcel message'));
        $this->assertSame([$theirs->thread_id], $this->hits('account:oxcel message'));
    }

    public function test_searching_covers_all_mail_but_not_trash_unless_asked(): void
    {
        $user = User::factory()->create();
        $kept = $this->store(['subject' => 'needle one']);
        $trashed = $this->store(['subject' => 'needle two', 'folderRemoteIds' => ['TRASH']]);

        // From the Starred view, with nothing starred: search still finds the
        // inbox message (scope widens to all), but not the trashed one.
        $page = $this->actingAs($user)->get('/inbox?view=starred&q=needle')->inertiaPage();
        $ids = collect($page['props']['threads']['data'])->pluck('id');
        $this->assertTrue($ids->contains($kept->thread_id));
        $this->assertFalse($ids->contains($trashed->thread_id));

        // in:trash pulls the trashed one in explicitly.
        $page = $this->actingAs($user)->get('/inbox?view=starred&q='.urlencode('in:trash needle'))->inertiaPage();
        $ids = collect($page['props']['threads']['data'])->pluck('id');
        $this->assertTrue($ids->contains($trashed->thread_id));
        $this->assertFalse($ids->contains($kept->thread_id));
    }
}

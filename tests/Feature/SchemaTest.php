<?php

namespace Tests\Feature;

use App\Enums\Provider;
use App\Models\Folder;
use App\Models\MailAccount;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_same_provider_message_cannot_be_stored_twice(): void
    {
        // This constraint is what makes every sync path idempotent: retried jobs and
        // full resyncs re-walk the mailbox and must not duplicate anything.
        $account = MailAccount::factory()->create();
        Message::factory()->for($account, 'mailAccount')->create(['provider_message_id' => 'dup']);

        $this->expectException(QueryException::class);

        Message::factory()->for($account, 'mailAccount')->create(['provider_message_id' => 'dup']);
    }

    public function test_the_same_provider_message_id_is_fine_on_a_different_account(): void
    {
        $a = MailAccount::factory()->create();
        $b = MailAccount::factory()->create();

        Message::factory()->for($a, 'mailAccount')->create(['provider_message_id' => 'shared']);
        Message::factory()->for($b, 'mailAccount')->create(['provider_message_id' => 'shared']);

        $this->assertSame(2, Message::count());
    }

    public function test_a_message_can_live_in_several_folders_at_once(): void
    {
        // Gmail's label model. If this were messages.folder_id, a message in INBOX
        // plus two labels could not be represented.
        $account = MailAccount::factory()->gmailApi()->create();
        $message = Message::factory()->for($account, 'mailAccount')->create();

        $inbox = Folder::create([
            'mail_account_id' => $account->id, 'remote_id' => 'INBOX',
            'name' => 'Inbox', 'role' => 'inbox', 'is_label' => true,
        ]);
        $label = Folder::create([
            'mail_account_id' => $account->id, 'remote_id' => 'Label_1',
            'name' => 'Receipts', 'is_label' => true,
        ]);

        $message->folders()->attach([$inbox->id, $label->id]);

        $this->assertCount(2, $message->refresh()->folders);
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $account = MailAccount::factory()->create([
            'credentials' => ['refresh_token' => 'super-secret-token'],
        ]);

        $raw = DB::table('mail_accounts')->where('id', $account->id)->value('credentials');

        $this->assertStringNotContainsString('super-secret-token', $raw);
        $this->assertSame('super-secret-token', $account->fresh()->credentials['refresh_token']);
    }

    public function test_full_text_search_finds_a_message_by_body(): void
    {
        $account = MailAccount::factory()->create();
        Message::factory()->for($account, 'mailAccount')->create([
            'subject' => 'Quarterly numbers',
            'body_text' => 'The reconciliation spreadsheet is attached.',
        ]);
        Message::factory()->for($account, 'mailAccount')->create([
            'subject' => 'Lunch',
            'body_text' => 'Thursday works for me.',
        ]);

        $hits = Message::whereRaw("search_vector @@ plainto_tsquery('simple', ?)", ['reconciliation'])->get();

        $this->assertCount(1, $hits);
        $this->assertSame('Quarterly numbers', $hits->first()->subject);
    }

    public function test_deleting_an_account_removes_its_messages(): void
    {
        $account = MailAccount::factory()->create();
        Message::factory()->for($account, 'mailAccount')->count(3)->create();

        $account->delete();

        $this->assertSame(0, Message::count());
    }

    public function test_provider_capabilities_are_declared_per_provider(): void
    {
        $this->assertTrue(Provider::GmailApi->hasMultiFolderMessages());
        $this->assertFalse(Provider::Graph->hasMultiFolderMessages());

        $this->assertTrue(Provider::Imap->supportsIdle());
        $this->assertFalse(Provider::GmailApi->supportsIdle());

        $this->assertFalse(Provider::Imap->hasNativeThreadId());
        $this->assertTrue(Provider::Graph->hasNativeThreadId());
    }
}

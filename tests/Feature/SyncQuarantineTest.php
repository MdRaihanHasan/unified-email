<?php

namespace Tests\Feature;

use App\Mail\Data\Address;
use App\Mail\Data\ChangeSet;
use App\Mail\Data\RemoteMessage;
use App\Mail\Support\MessageWriter;
use App\Models\MailAccount;
use App\Models\Message;
use App\Models\SyncFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncQuarantineTest extends TestCase
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

    private function remote(string $id, array $overrides = []): RemoteMessage
    {
        return new RemoteMessage(...[
            'providerMessageId' => $id,
            'rfc822MessageId' => "<{$id}@example.com>",
            'from' => new Address('sender@example.com', 'Sender'),
            'to' => [new Address('me@company.com')],
            'subject' => 'Fine message',
            'receivedAt' => new \DateTimeImmutable('2026-08-01 09:00:00'),
            ...$overrides,
        ]);
    }

    /** Invalid UTF-8 that Postgres will reject at insert — the exact live failure class. */
    private function poison(string $id): RemoteMessage
    {
        return $this->remote($id, ['subject' => "broken \xC3\x28 bytes"]);
    }

    public function test_one_poisoned_message_does_not_stop_the_rest_of_a_change_set(): void
    {
        $result = $this->writer->applyChangeSet($this->account, new ChangeSet(
            created: [$this->remote('m1'), $this->poison('m2'), $this->remote('m3')],
        ));

        $this->assertSame(2, $result['created']);
        $this->assertSame(2, Message::count());

        $failure = SyncFailure::sole();
        $this->assertSame('m2', $failure->provider_message_id);
        $this->assertSame($this->account->id, $failure->mail_account_id);
        $this->assertNotSame('', $failure->error);
    }

    public function test_the_same_message_failing_again_counts_up_instead_of_duplicating(): void
    {
        foreach (range(1, 3) as $attempt) {
            $this->writer->applyChangeSet($this->account, new ChangeSet(created: [$this->poison('m2')]));
        }

        $failure = SyncFailure::sole();
        $this->assertSame(3, $failure->occurrences);
    }

    public function test_quarantine_rows_die_with_their_account(): void
    {
        $this->writer->applyChangeSet($this->account, new ChangeSet(created: [$this->poison('m2')]));

        $this->account->delete();

        $this->assertSame(0, SyncFailure::count());
    }
}

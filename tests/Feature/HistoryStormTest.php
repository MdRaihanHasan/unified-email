<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Jobs\BackfillJob;
use App\Jobs\SyncAccountJob;
use App\Mail\Data\Address;
use App\Mail\Data\ChangeSet;
use App\Mail\Data\RemoteMessage;
use App\Mail\Data\SyncCursor;
use App\Mail\Exceptions\RateLimitedException;
use App\Mail\Support\MessageWriter;
use App\Models\MailAccount;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\UsesFakeProvider;
use Tests\TestCase;

class HistoryStormTest extends TestCase
{
    use RefreshDatabase, UsesFakeProvider;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeProvider();
        $this->account = MailAccount::factory()->gmailApi()->create([
            'status' => AccountStatus::Active,
            'backfill_done_at' => now(),
            'sync_cursor' => ['historyId' => '100'],
        ]);
    }

    private function remote(string $id): RemoteMessage
    {
        return new RemoteMessage(
            providerMessageId: $id,
            rfc822MessageId: "<{$id}@example.com>",
            from: new Address('sender@example.com'),
            subject: "Message {$id}",
            receivedAt: new \DateTimeImmutable('2026-08-01 09:00:00'),
        );
    }

    public function test_a_partial_change_set_is_committed_and_the_job_continues_itself(): void
    {
        Queue::fake();

        $this->provider->changeSets = [new ChangeSet(
            created: [$this->remote('a')],
            cursor: new SyncCursor(['historyId' => '150']),
            hasMore: true,
        )];

        (new SyncAccountJob($this->account))->handle(app(MessageWriter::class));

        // The batch is stored and its cursor durably committed BEFORE continuing,
        // so a crash between jobs loses nothing.
        $this->assertSame(1, Message::count());
        $this->assertSame(['historyId' => '150'], $this->account->fresh()->sync_cursor);
        Queue::assertPushed(SyncAccountJob::class, fn ($job) => $job->account->is($this->account));
    }

    public function test_a_complete_change_set_does_not_chain_another_job(): void
    {
        Queue::fake();

        $this->provider->changeSets = [new ChangeSet(
            created: [$this->remote('a')],
            cursor: new SyncCursor(['historyId' => '150']),
        )];

        (new SyncAccountJob($this->account))->handle(app(MessageWriter::class));

        Queue::assertNotPushed(SyncAccountJob::class);
    }

    public function test_a_rate_limited_sync_reschedules_instead_of_failing(): void
    {
        Queue::fake();

        $this->provider->changesFailure = new RateLimitedException('slow down', retryAfterSeconds: 90);

        (new SyncAccountJob($this->account))->handle(app(MessageWriter::class));

        $account = $this->account->fresh();
        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertSame(['historyId' => '100'], $account->sync_cursor, 'the cursor must not move');
        Queue::assertPushed(SyncAccountJob::class, fn ($job) => $job->delay !== null);
    }

    public function test_a_rate_limited_backfill_reschedules_instead_of_burning_a_retry(): void
    {
        Queue::fake();

        $this->account->update(['backfill_done_at' => null, 'sync_cursor' => null]);
        $this->provider->foldersFailure = new RateLimitedException('slow down');

        (new BackfillJob($this->account))->handle(app(MessageWriter::class));

        $this->assertSame(AccountStatus::Active, $this->account->fresh()->status);
        Queue::assertPushed(BackfillJob::class, fn ($job) => $job->delay !== null);
    }
}

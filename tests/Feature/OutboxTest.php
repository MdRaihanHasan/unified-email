<?php

namespace Tests\Feature;

use App\Enums\OutboundStatus;
use App\Jobs\SendMessageJob;
use App\Models\MailAccount;
use App\Models\OutboundMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = MailAccount::factory()->gmailApi()->create();
        $this->actingAs(User::factory()->create());
    }

    private function outbound(OutboundStatus $status, array $overrides = []): OutboundMessage
    {
        return OutboundMessage::create([
            'mail_account_id' => $this->account->id,
            'type' => 'new',
            'status' => $status,
            'subject' => 'Hello',
            'to_addrs' => [['address' => 'someone@example.com', 'name' => 'Someone']],
            ...$overrides,
        ]);
    }

    // ---- the page ----------------------------------------------------------

    public function test_the_outbox_lists_undelivered_sends_and_drafts_separately(): void
    {
        $this->outbound(OutboundStatus::Queued);
        $this->outbound(OutboundStatus::Failed, ['error' => 'Gmail said no']);
        $this->outbound(OutboundStatus::Draft);
        $this->outbound(OutboundStatus::Sent); // must appear nowhere

        $this->get('/outbox')->assertInertia(fn (Assert $page) => $page
            ->component('Outbox/Index')
            ->has('undelivered', 2)
            ->has('drafts', 1)
            ->where('undelivered', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['status'] === 'failed' && $item['error'] === 'Gmail said no')));
    }

    public function test_a_failed_send_can_be_retried_from_the_outbox(): void
    {
        Queue::fake();

        $failed = $this->outbound(OutboundStatus::Failed, ['error' => 'boom']);

        $this->post("/outbox/{$failed->id}/retry")->assertRedirect();

        $failed->refresh();
        $this->assertSame(OutboundStatus::Queued, $failed->status);
        $this->assertNull($failed->error);
        Queue::assertPushed(SendMessageJob::class, fn ($job) => $job->outbound->is($failed));
    }

    public function test_a_sent_message_cannot_be_retried_or_discarded(): void
    {
        Queue::fake();

        $sent = $this->outbound(OutboundStatus::Sent);

        $this->post("/outbox/{$sent->id}/retry");
        $this->delete("/outbox/{$sent->id}");

        $this->assertSame(OutboundStatus::Sent, $sent->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_discarding_from_the_outbox_deletes_the_row(): void
    {
        $draft = $this->outbound(OutboundStatus::Draft);

        $this->delete("/outbox/{$draft->id}")->assertRedirect();

        $this->assertDatabaseMissing('outbound_messages', ['id' => $draft->id]);
    }

    // ---- the sweeper -------------------------------------------------------

    public function test_the_sweeper_redispatches_a_queued_row_whose_job_vanished(): void
    {
        Queue::fake();

        $lost = $this->outbound(OutboundStatus::Queued);
        $lost->timestamps = false;
        $lost->update(['updated_at' => now()->subMinutes(10)]);

        $fresh = $this->outbound(OutboundStatus::Queued); // just dispatched, leave alone

        $this->artisan('mail:sweep-outbound')->assertSuccessful();

        Queue::assertPushed(SendMessageJob::class, 1);
        Queue::assertPushed(SendMessageJob::class, fn ($job) => $job->outbound->is($lost));
        $this->assertTrue($fresh->refresh()->status === OutboundStatus::Queued);
    }

    public function test_the_sweeper_fails_a_send_stuck_mid_flight(): void
    {
        Queue::fake();

        $stuck = $this->outbound(OutboundStatus::Sending);
        $stuck->timestamps = false;
        $stuck->update(['updated_at' => now()->subMinutes(30)]);

        $this->artisan('mail:sweep-outbound')->assertSuccessful();

        $stuck->refresh();
        $this->assertSame(OutboundStatus::Failed, $stuck->status);
        $this->assertStringContainsString('Retry it from the Outbox', $stuck->error);
    }

    public function test_the_sweeper_leaves_recent_rows_alone(): void
    {
        Queue::fake();

        $this->outbound(OutboundStatus::Queued);
        $this->outbound(OutboundStatus::Sending);

        $this->artisan('mail:sweep-outbound')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(0, OutboundMessage::where('status', OutboundStatus::Failed)->count());
    }
}

<?php

namespace Tests\Feature;

use App\Enums\FolderRole;
use App\Jobs\BackfillJob;
use App\Mail\Data\RemoteFolder;
use App\Mail\Support\MessageWriter;
use App\Models\Folder;
use App\Models\MailAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeepBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_older_widens_the_window_and_rewalks(): void
    {
        Queue::fake();

        $account = MailAccount::factory()->gmailApi()->create(['backfill_done_at' => now()]);
        app(MessageWriter::class)->storeFolders($account, [
            new RemoteFolder('INBOX', 'Inbox', FolderRole::Inbox, isLabel: true),
        ]);
        Folder::query()->update(['backfill_done_at' => now()]);

        $this->actingAs(User::factory()->create())
            ->post("/accounts/{$account->id}/import-older")
            ->assertRedirect();

        $account->refresh();
        $this->assertSame(0, $account->backfill_days, '0 means the whole mailbox');
        $this->assertNull($account->backfill_done_at, 'the walk restarts');
        $this->assertSame(0, Folder::whereNotNull('backfill_done_at')->count());
        Queue::assertPushed(BackfillJob::class);
    }

    public function test_import_older_waits_for_the_first_import(): void
    {
        Queue::fake();

        $account = MailAccount::factory()->gmailApi()->create(['backfill_done_at' => null]);

        $this->actingAs(User::factory()->create())
            ->post("/accounts/{$account->id}/import-older");

        $this->assertNull($account->fresh()->backfill_days);
        Queue::assertNothingPushed();
    }
}

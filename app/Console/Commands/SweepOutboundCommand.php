<?php

namespace App\Console\Commands;

use App\Enums\OutboundStatus;
use App\Jobs\SendMessageJob;
use App\Models\OutboundMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recovers sends whose queue job vanished.
 *
 * A row sits in `queued` with no job behind it when Redis restarts empty or a
 * worker dies between the dispatch and the pickup; one sits in `sending` forever
 * when the worker is killed mid-send. Neither has any retry of its own — the row
 * just waits, invisibly, which is the exact failure the audit watched happen live.
 *
 * Requeueing is safe: SendMessageJob reuses the stored Message-ID, so even a
 * send that actually left before the worker died is deduplicated by the
 * recipient's mail client, and WithoutOverlapping stops a double-run.
 */
class SweepOutboundCommand extends Command
{
    protected $signature = 'mail:sweep-outbound
        {--queued-after=5 : Minutes a queued row may sit before it is re-dispatched}
        {--sending-after=15 : Minutes a sending row may sit before it is marked failed}';

    protected $description = 'Re-dispatch queued sends whose job vanished, and fail sends stuck mid-flight';

    public function handle(): int
    {
        $requeued = 0;

        // touch() first so a row is not re-dispatched again by every later sweep
        // while the queue is slow; the job itself is idempotent regardless.
        OutboundMessage::query()
            ->where('status', OutboundStatus::Queued)
            ->where('updated_at', '<', now()->subMinutes((int) $this->option('queued-after')))
            ->each(function (OutboundMessage $outbound) use (&$requeued) {
                $outbound->touch();
                SendMessageJob::dispatch($outbound);
                $requeued++;
            });

        $stalled = OutboundMessage::query()
            ->where('status', OutboundStatus::Sending)
            ->where('updated_at', '<', now()->subMinutes((int) $this->option('sending-after')))
            ->get()
            ->each(fn (OutboundMessage $outbound) => $outbound->update([
                'status' => OutboundStatus::Failed,
                'error' => 'Stalled mid-send — the worker likely died. Retry it from the Outbox; '
                    .'the reused Message-ID means a duplicate delivery collapses into one.',
            ]))
            ->count();

        if ($requeued > 0 || $stalled > 0) {
            Log::warning('Outbound sweep intervened', ['requeued' => $requeued, 'stalled' => $stalled]);
        }

        $this->info("Re-dispatched {$requeued} queued, failed {$stalled} stalled.");

        return self::SUCCESS;
    }
}

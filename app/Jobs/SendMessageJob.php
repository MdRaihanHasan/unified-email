<?php

namespace App\Jobs;

use App\Models\OutboundMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly OutboundMessage $outbound) {}

    public function handle(): void
    {
        // TODO Phase 3: build an OutboundDraft, attach ReplyHeaders::for($parent) when
        // replying, hand it to the provider, then record the returned provider id.
        // The sent copy arrives back through normal sync from the Sent folder.
        throw new \LogicException('SendMessageJob is not implemented yet (roadmap Phase 3).');
    }
}

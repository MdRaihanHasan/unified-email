<?php

namespace App\Jobs;

use App\Mail\Data\FlagChange;
use App\Models\MailAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes a local read/star change up to the provider.
 *
 * The UI writes to Postgres first and queues this, so marking mail read stays
 * instant. A failure here has to revert the local flag, otherwise our state and
 * the provider's drift apart with nothing to reconcile them.
 *
 * @param  list<string>  $providerMessageIds
 */
class PushFlagsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly MailAccount $account,
        public readonly array $providerMessageIds,
        public readonly FlagChange $change,
    ) {}

    public function handle(): void
    {
        // TODO Phase 1: $this->account->driver()->applyFlags(...) with local revert on failure.
        throw new \LogicException('PushFlagsJob is not implemented yet (roadmap Phase 1).');
    }
}

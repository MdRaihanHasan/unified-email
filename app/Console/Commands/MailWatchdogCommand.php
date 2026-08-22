<?php

namespace App\Console\Commands;

use App\Models\MailAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reports accounts whose last successful sync is too old.
 *
 * Every failure mode in this design is quiet: an expired delta cursor, a dead IDLE
 * daemon, a rotated app password. None of them raise an error on the happy path —
 * mail simply stops arriving. This is the check that makes that visible.
 */
class MailWatchdogCommand extends Command
{
    protected $signature = 'mail:watchdog';

    protected $description = 'Report mail accounts that have gone stale';

    public function handle(): int
    {
        $threshold = (int) config('mail_providers.sync.stale_after_minutes');

        $stale = MailAccount::all()->filter(fn (MailAccount $account) => $account->isStale($threshold));

        if ($stale->isEmpty()) {
            $this->components->info('All accounts syncing.');

            return self::SUCCESS;
        }

        foreach ($stale as $account) {
            $last = $account->last_synced_at?->diffForHumans() ?? 'never';

            $this->components->warn("{$account->email} last synced {$last}");

            Log::warning('Mail account is stale', [
                'account' => $account->email,
                'provider' => $account->provider->value,
                'last_synced_at' => $account->last_synced_at,
                'status' => $account->status->value,
            ]);
        }

        return self::FAILURE;
    }
}

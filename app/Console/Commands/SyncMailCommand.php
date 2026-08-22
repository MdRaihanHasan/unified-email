<?php

namespace App\Console\Commands;

use App\Jobs\SyncAccountJob;
use App\Models\MailAccount;
use Illuminate\Console\Command;

class SyncMailCommand extends Command
{
    protected $signature = 'mail:sync
                            {--account= : Sync only this account id or email}
                            {--all : Include IDLE-driven accounts too}';

    protected $description = 'Queue an incremental sync for the polled mail accounts';

    public function handle(): int
    {
        $accounts = MailAccount::query()
            ->when($this->option('account'), fn ($query, $needle) => $query
                ->where('id', is_numeric($needle) ? $needle : 0)
                ->orWhere('email', $needle))
            ->get()
            ->filter(fn (MailAccount $account) => $account->status->shouldSync())
            // IMAP accounts get their changes pushed by the IDLE daemon, so polling
            // them on the same tick is duplicated work. --all forces them in, which
            // is the safety net for a daemon that has died unnoticed.
            ->filter(fn (MailAccount $account) => $this->option('all')
                || ! $account->provider->supportsIdle());

        if ($accounts->isEmpty()) {
            $this->components->info('No accounts to sync.');

            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            SyncAccountJob::dispatch($account);
            $this->line("  queued <fg=cyan>{$account->email}</> ({$account->provider->value})");
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\SyncAccountJob;
use App\Models\MailAccount;
use Illuminate\Console\Command;

/**
 * Long-running IMAP IDLE daemon: one process per IMAP account.
 *
 * This is the reason the app cannot run on a serverless platform. IDLE means
 * holding a TCP connection open for as long as the mailbox is connected, which no
 * function-invocation model allows. It is also why real-time costs nothing here:
 * the connection is outbound, so there is no public endpoint, tunnel or static IP
 * anywhere in the design.
 */
class MailIdleCommand extends Command
{
    protected $signature = 'mail:idle {account : Account id or email}';

    protected $description = 'Hold an IMAP IDLE connection open and sync on every change';

    public function handle(): int
    {
        $account = MailAccount::query()
            ->where('id', is_numeric($this->argument('account')) ? $this->argument('account') : 0)
            ->orWhere('email', $this->argument('account'))
            ->first();

        if ($account === null) {
            $this->components->error("Unknown account: {$this->argument('account')}");

            return self::FAILURE;
        }

        if (! $account->provider->supportsIdle()) {
            $this->components->error("{$account->email} is a {$account->provider->value} account; it is polled, not idled.");

            return self::FAILURE;
        }

        // TODO Phase 2:
        //  - open the client, select INBOX, issue IDLE
        //  - on any untagged response, dispatch SyncAccountJob and keep idling
        //  - re-issue IDLE every sync.idle_refresh_minutes, since Gmail drops an
        //    idle connection at around 29 minutes
        //  - reconnect with exponential backoff, and never exit silently: a dead
        //    daemon means an account that stops receiving mail with no error anywhere
        throw new \LogicException('MailIdleCommand is not implemented yet (roadmap Phase 2).');
    }
}

<?php

namespace App\Http\Controllers;

use App\Jobs\SyncAccountJob;
use App\Models\MailAccount;
use Illuminate\Http\RedirectResponse;

/**
 * The "Sync now" button. The scheduler already polls every minute; this exists so
 * that a person who just sent something from their phone's Gmail — or who simply
 * doubts the app — has a lever to pull and gets feedback for pulling it.
 */
class SyncController
{
    public function store(): RedirectResponse
    {
        $accounts = MailAccount::all()->filter(
            fn (MailAccount $account) => $account->status->shouldSync()
                // IDLE-driven accounts are pushed by their daemon, not polled.
                && ! $account->provider->supportsIdle(),
        );

        foreach ($accounts as $account) {
            SyncAccountJob::dispatch($account);
        }

        return back()->with('message', $accounts->isEmpty()
            ? 'Nothing to sync — connect a mailbox first.'
            : 'Checking for new mail…');
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\Provider;
use App\Jobs\BackfillJob;
use App\Jobs\RemoveAccountJob;
use App\Mail\Providers\Gmail\ClientFactory;
use App\Models\MailAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController
{
    public function __construct(private readonly ClientFactory $google) {}

    public function index(): Response
    {
        // Accounts themselves come through the Inertia shared props, since the
        // sidebar needs them on every page.
        return Inertia::render('Accounts/Index', [
            'googleConfigured' => $this->google->configured(),
            'providers' => collect(Provider::cases())->map(fn (Provider $provider) => [
                'value' => $provider->value,
                'label' => $provider->label(),
                'supports_idle' => $provider->supportsIdle(),
            ]),
        ]);
    }

    /** Rename, sender identity, signature, and the pause toggle. */
    public function update(Request $request, MailAccount $account): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:60'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'signature_html' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'paused' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('paused', $data)) {
            // Pausing only makes sense from a working state; an auth_error account
            // stays an auth_error account until it is reconnected.
            if (in_array($account->status, [AccountStatus::Active, AccountStatus::Disabled], true)) {
                $account->status = $data['paused'] ? AccountStatus::Disabled : AccountStatus::Active;
            }

            unset($data['paused']);
        }

        $account->fill($data)->save();

        return back()->with('message', "{$account->email} updated.");
    }

    /**
     * Widen the import window to the whole mailbox and re-walk it.
     *
     * The walk is the ordinary resumable backfill: every store is an upsert, so
     * the mail already held is re-confirmed and only the older tail is new work.
     */
    public function importOlder(MailAccount $account): RedirectResponse
    {
        if (! $account->hasFinishedBackfill()) {
            return back()->with('message', 'The first import is still running — older mail can follow once it finishes.');
        }

        $account->update(['backfill_days' => 0, 'backfill_done_at' => null]);
        $account->folders()->update(['backfill_done_at' => null, 'backfill_cursor' => null]);

        BackfillJob::dispatch($account);

        return back()->with('message', "Importing {$account->email}'s full history — mail keeps working while it runs.");
    }

    public function destroy(MailAccount $account): RedirectResponse
    {
        // Best-effort: tell Google the grant is dead so it does not linger on the
        // user's third-party-access page. A failure here (token already revoked,
        // network down) must not block the removal — the rows go either way.
        $refreshToken = $account->credentials['refresh_token'] ?? null;

        if ($account->provider === Provider::GmailApi && filled($refreshToken) && $this->google->configured()) {
            try {
                $this->google->forConsent()->revokeToken($refreshToken);
            } catch (\Throwable) {
                // Nothing to do; the account is being removed regardless.
            }

            $this->google->forgetToken($account);
        }

        // The rows go in a queued job: cascading a whole mailbox inside this
        // request is a timeout with partial cleanup on any real account. Disabled
        // stops the sync immediately; the stamp lets the UI say "removing…".
        $account->update([
            'status' => AccountStatus::Disabled,
            'removal_requested_at' => now(),
        ]);

        RemoveAccountJob::dispatch($account);

        return redirect()->route('accounts')
            ->with('message', "Removing {$account->email} — its mail disappears as the cleanup runs.");
    }
}

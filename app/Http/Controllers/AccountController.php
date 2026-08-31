<?php

namespace App\Http\Controllers;

use App\Enums\Provider;
use App\Mail\Providers\Gmail\ClientFactory;
use App\Mail\Support\MessageWriter;
use App\Models\MailAccount;
use App\Models\Thread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
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

    public function destroy(MailAccount $account, MessageWriter $writer): RedirectResponse
    {
        // Captured before the delete: the cascade takes the message rows with it,
        // and afterwards there is nothing left to say which threads were touched.
        $threadIds = $account->messages()->distinct()->pluck('thread_id');

        // Staged compose attachments live on disk, outside the database cascade.
        foreach ($account->outboundMessages()->whereNotNull('attachments')->pluck('attachments') as $staged) {
            foreach ($staged ?? [] as $attachment) {
                if (! empty($attachment['path'])) {
                    Storage::disk('local')->delete($attachment['path']);
                }
            }
        }

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
        }

        // Folders, messages, attachments and pivots cascade in the database, and
        // any queued job for this account discards itself when the model is gone.
        $account->delete();

        // Threads whose only messages were this account's are now empty; ones
        // shared with another mailbox (an RFC-header merge) survive and need
        // their derived counters redone.
        $threadIds->chunk(1000)->each(function ($chunk) use ($writer) {
            Thread::whereIn('id', $chunk)->whereDoesntHave('messages')->delete();
            Thread::whereIn('id', $chunk)->pluck('id')->each(fn (int $id) => $writer->recountThread($id));
        });

        return redirect()->route('accounts')->with('message', "{$account->email} removed.");
    }
}

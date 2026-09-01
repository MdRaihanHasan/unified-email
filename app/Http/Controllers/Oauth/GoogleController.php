<?php

namespace App\Http\Controllers\Oauth;

use App\Enums\AccountStatus;
use App\Enums\Provider;
use App\Jobs\BackfillJob;
use App\Mail\Providers\Gmail\ClientFactory;
use App\Models\MailAccount;
use Google\Service\Gmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connects a Gmail mailbox — personal or Workspace, through the same OAuth client.
 *
 * The consent screen's publishing status must be "In production": that is what
 * stops refresh tokens being revoked after seven days, and what lets an unverified
 * app keep using restricted scopes. See config/mail_providers.php.
 */
class GoogleController
{
    private const STATE_KEY = 'google_oauth_state';

    public function __construct(private readonly ClientFactory $clients) {}

    public function connect(Request $request): RedirectResponse
    {
        if (! $this->clients->configured()) {
            return redirect()->route('accounts')
                ->with('message', 'Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET first.');
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_KEY, $state);

        $client = $this->clients->forConsent();

        // setState, not a &state= appended to createAuthUrl(): the library already
        // emits an empty state parameter, so appending produces a second one and
        // whoever reads the first sees nothing.
        $client->setState($state);

        return redirect()->away($client->createAuthUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        // Google reports a declined consent screen here rather than by not calling
        // back at all, so a missing code is normal, not an error to log loudly.
        if ($request->filled('error')) {
            return redirect()->route('accounts')
                ->with('message', 'Google did not grant access: '.$request->string('error'));
        }

        $expected = $request->session()->pull(self::STATE_KEY);

        if (blank($expected) || ! hash_equals($expected, (string) $request->string('state'))) {
            return redirect()->route('accounts')
                ->with('message', 'That sign-in link had expired. Please try connecting again.');
        }

        if (! $request->filled('code')) {
            return redirect()->route('accounts')->with('message', 'Google returned no authorization code.');
        }

        try {
            $account = $this->exchange($request->string('code'));
        } catch (Throwable $e) {
            Log::warning('Google OAuth exchange failed', ['reason' => $e->getMessage()]);

            return redirect()->route('accounts')
                ->with('message', 'Could not finish connecting that mailbox: '.$e->getMessage());
        }

        BackfillJob::dispatch($account);

        return redirect()->route('inbox')
            ->with('message', "Connected {$account->email}. Importing recent mail now.");
    }

    private function exchange(string $code): MailAccount
    {
        $client = $this->clients->forConsent();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new \RuntimeException($token['error_description'] ?? $token['error']);
        }

        // Without a refresh token the connection dies with the first access token,
        // so this is worth failing loudly on rather than storing a broken account.
        if (empty($token['refresh_token'])) {
            throw new \RuntimeException(
                'Google returned no refresh token. Revoke this app at myaccount.google.com/permissions and connect again.',
            );
        }

        $client->setAccessToken($token);
        $email = (new Gmail($client))->users->getProfile('me')->getEmailAddress();

        if (blank($email)) {
            throw new \RuntimeException('Could not read the mailbox address from Google.');
        }

        // Reconnecting an existing mailbox replaces its credentials and clears the
        // error, rather than creating a second copy of the same inbox. The label
        // and sender name are only defaulted on first connect — a reconnect must
        // not undo what the user renamed on the accounts page.
        $account = MailAccount::firstOrNew(['email' => $email, 'provider' => Provider::GmailApi]);

        if (! $account->exists) {
            $account->label = $this->label($email);
        }

        $account->fill([
            'credentials' => ['refresh_token' => $token['refresh_token']],
            'status' => AccountStatus::Active,
            'last_error' => null,
        ])->save();

        // A cached access token from the old grant must not outlive the reconnect.
        $this->clients->forgetToken($account);

        return $account;
    }

    /** A readable sidebar name: "Personal" for gmail.com, otherwise the domain. */
    private function label(string $email): string
    {
        $domain = Str::after($email, '@');

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            return 'Personal';
        }

        return Str::title(Str::before($domain, '.'));
    }
}

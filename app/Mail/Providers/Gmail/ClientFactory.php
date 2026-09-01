<?php

namespace App\Mail\Providers\Gmail;

use App\Mail\Exceptions\AuthenticationFailedException;
use App\Models\MailAccount;
use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Cache;

/**
 * The one place that knows our Google OAuth parameters.
 *
 * Both the connect flow and the provider need a configured client, and having each
 * build its own invites them to drift — a scope added in one and not the other
 * produces a token that works for reading and fails on send.
 */
class ClientFactory
{
    /** A client configured for the consent flow, with no token attached. */
    public function forConsent(): GoogleClient
    {
        $client = $this->base();
        $client->setRedirectUri((string) config('mail_providers.google.redirect_uri'));

        // access_type=offline plus an explicit consent prompt is what actually
        // returns a refresh token: Google omits it when re-authorizing an existing
        // grant, which is exactly when a reconnect happens.
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    /** A client already exchanged for an access token on the account's behalf. */
    public function forAccount(MailAccount $account): GoogleClient
    {
        $refreshToken = $account->credentials['refresh_token'] ?? null;

        if (blank($refreshToken)) {
            throw new AuthenticationFailedException("Account {$account->email} has no refresh token.");
        }

        $client = $this->base();

        // Access tokens live about an hour; exchanging the refresh token on every
        // job was ~5.7k token calls a day across four accounts polled per minute.
        // Cached with a five-minute safety margin; a token revoked mid-window
        // surfaces as a 401 on the next API call, which is handled as auth failure.
        $cacheKey = 'gmail-access-token:'.$account->id;
        $token = Cache::get($cacheKey);

        if ($token === null) {
            $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);

            if (isset($token['error'])) {
                throw new AuthenticationFailedException(
                    "Google rejected the refresh token for {$account->email}: "
                    .($token['error_description'] ?? $token['error']),
                );
            }

            Cache::put($cacheKey, $token, max(60, (int) ($token['expires_in'] ?? 3600) - 300));
        } else {
            $client->setAccessToken($token);
        }

        return $client;
    }

    /** Drop the cached access token — on disconnect, reconnect, or removal. */
    public function forgetToken(MailAccount $account): void
    {
        Cache::forget('gmail-access-token:'.$account->id);
    }

    public function configured(): bool
    {
        return filled(config('mail_providers.google.client_id'))
            && filled(config('mail_providers.google.client_secret'));
    }

    private function base(): GoogleClient
    {
        $client = new GoogleClient;
        $client->setClientId((string) config('mail_providers.google.client_id'));
        $client->setClientSecret((string) config('mail_providers.google.client_secret'));
        $client->setScopes(config('mail_providers.google.scopes'));
        $client->setAccessType('offline');

        return $client;
    }
}

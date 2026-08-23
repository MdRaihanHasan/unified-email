<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\Provider;
use App\Jobs\BackfillJob;
use App\Mail\Providers\Gmail\ClientFactory;
use App\Models\MailAccount;
use App\Models\User;
use Google\Client as GoogleClient;
use Google\Service\Gmail\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleConnectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        config([
            'mail_providers.google.client_id' => 'test-client-id.apps.googleusercontent.com',
            'mail_providers.google.client_secret' => 'test-secret',
            'mail_providers.google.redirect_uri' => 'http://localhost:8000/gmail/callback',
        ]);
    }

    public function test_connecting_sends_you_to_google_with_the_parameters_that_matter(): void
    {
        $response = $this->actingAs($this->user)->get('/gmail/connect')->assertRedirect();
        $url = $response->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/', $url);
        $this->assertStringContainsString('test-client-id', $url);
        $this->assertStringContainsString('gmail.modify', $url);
        $this->assertStringContainsString('gmail.send', $url);

        // Without both of these Google returns no refresh token, and the connection
        // dies with the first access token.
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent', $url);

        // Assert the value, not the substring: the library emits an empty state=
        // of its own, so "contains state=" passes even when ours never arrives.
        $state = session('google_oauth_state');
        $this->assertNotEmpty($state);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame($state, $query['state'] ?? null);
    }

    public function test_connecting_without_credentials_configured_says_so(): void
    {
        config(['mail_providers.google.client_id' => null]);

        $this->actingAs($this->user)->get('/gmail/connect')
            ->assertRedirect(route('accounts'))
            ->assertSessionHas('message', fn (string $m) => str_contains($m, 'GOOGLE_CLIENT_ID'));
    }

    public function test_a_guest_cannot_start_a_connection(): void
    {
        $this->get('/gmail/connect')->assertRedirect('/login');
        $this->get('/gmail/callback?code=x')->assertRedirect('/login');
    }

    // ---- callback ---------------------------------------------------------

    public function test_a_declined_consent_screen_is_reported_not_logged_as_a_crash(): void
    {
        // Google reports refusal here rather than by never calling back.
        $this->actingAs($this->user)
            ->get('/gmail/callback?error=access_denied')
            ->assertRedirect(route('accounts'))
            ->assertSessionHas('message', fn (string $m) => str_contains($m, 'access_denied'));

        $this->assertSame(0, MailAccount::count());
    }

    public function test_a_callback_with_no_matching_state_is_refused(): void
    {
        // Without this check anyone could hand the signed-in user a link that
        // attaches an attacker's mailbox to their instance.
        $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 'the-real-state'])
            ->get('/gmail/callback?code=abc&state=a-different-state')
            ->assertRedirect(route('accounts'))
            ->assertSessionHas('message', fn (string $m) => str_contains($m, 'expired'));

        $this->assertSame(0, MailAccount::count());
    }

    public function test_a_callback_with_no_state_in_session_is_refused(): void
    {
        $this->actingAs($this->user)
            ->get('/gmail/callback?code=abc&state=anything')
            ->assertRedirect(route('accounts'));

        $this->assertSame(0, MailAccount::count());
    }

    public function test_a_callback_with_a_valid_state_but_no_code_is_reported(): void
    {
        $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 'state-1'])
            ->get('/gmail/callback?state=state-1')
            ->assertRedirect(route('accounts'))
            ->assertSessionHas('message', fn (string $m) => str_contains($m, 'authorization code'));
    }

    public function test_the_state_is_consumed_so_a_callback_cannot_be_replayed(): void
    {
        $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 'state-1'])
            ->get('/gmail/callback?state=state-1');

        $this->assertNull(session('google_oauth_state'));
    }

    // ---- exchange ---------------------------------------------------------

    public function test_a_grant_without_a_refresh_token_is_rejected_rather_than_stored(): void
    {
        // Google omits the refresh token when re-authorizing an existing grant. A
        // half-connected account would sync until the access token expired and then
        // fail with no explanation, so it is refused outright.
        $this->swapFactory(['access_token' => 'at', 'expires_in' => 3600]);

        $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 'state-1'])
            ->get('/gmail/callback?code=abc&state=state-1')
            ->assertRedirect(route('accounts'))
            ->assertSessionHas('message', fn (string $m) => str_contains($m, 'no refresh token'));

        $this->assertSame(0, MailAccount::count());
    }

    public function test_an_error_from_the_token_endpoint_is_surfaced(): void
    {
        $this->swapFactory(['error' => 'invalid_grant', 'error_description' => 'Bad code']);

        $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 'state-1'])
            ->get('/gmail/callback?code=abc&state=state-1')
            ->assertRedirect(route('accounts'))
            ->assertSessionHas('message', fn (string $m) => str_contains($m, 'Bad code'));

        $this->assertSame(0, MailAccount::count());
    }

    public function test_reconnecting_replaces_the_credentials_rather_than_adding_a_mailbox(): void
    {
        Queue::fake();

        $existing = MailAccount::factory()->gmailApi()->create([
            'email' => 'me@company.com',
            'credentials' => ['refresh_token' => 'stale'],
            'status' => AccountStatus::AuthError,
            'last_error' => 'Google rejected our credentials.',
        ]);

        $this->completeExchange('me@company.com', 'fresh-refresh-token');

        $this->assertSame(1, MailAccount::count());

        $account = $existing->fresh();
        $this->assertSame('fresh-refresh-token', $account->credentials['refresh_token']);
        $this->assertSame(AccountStatus::Active, $account->status);
        $this->assertNull($account->last_error);
    }

    public function test_a_new_mailbox_is_created_labelled_and_sent_to_backfill(): void
    {
        Queue::fake();

        $this->completeExchange('someone@gmail.com', 'refresh-1')
            ->assertRedirect(route('inbox'))
            ->assertSessionHas('message', fn (string $m) => str_contains($m, 'someone@gmail.com'));

        $account = MailAccount::sole();
        $this->assertSame(Provider::GmailApi, $account->provider);
        $this->assertSame('Personal', $account->label, 'gmail.com reads as the personal mailbox');
        $this->assertSame('refresh-1', $account->credentials['refresh_token']);

        Queue::assertPushed(BackfillJob::class);
    }

    public function test_a_workspace_mailbox_is_labelled_from_its_domain(): void
    {
        Queue::fake();

        $this->completeExchange('me@bixcel.com.au', 'refresh-2');

        $this->assertSame('Bixcel', MailAccount::sole()->label);
    }

    public function test_the_stored_refresh_token_is_encrypted_at_rest(): void
    {
        Queue::fake();

        $this->completeExchange('me@company.com', 'very-secret-token');

        $raw = \DB::table('mail_accounts')->value('credentials');

        $this->assertStringNotContainsString('very-secret-token', $raw);
    }

    /**
     * Run the callback with a token response and a resolved mailbox address.
     *
     * The token exchange and the profile read are the two calls that need Google, so
     * they are the two things stubbed; everything either side is the real code path.
     */
    private function completeExchange(string $email, string $refreshToken)
    {
        $this->swapFactory(
            ['access_token' => 'at', 'refresh_token' => $refreshToken, 'expires_in' => 3600],
            $email,
        );

        return $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 'state-1'])
            ->get('/gmail/callback?code=abc&state=state-1');
    }

    private function swapFactory(array $tokenResponse, ?string $email = null): void
    {
        $this->app->instance(ClientFactory::class, new class($tokenResponse, $email) extends ClientFactory
        {
            public function __construct(private array $tokenResponse, private ?string $email) {}

            public function forConsent(): GoogleClient
            {
                return new class($this->tokenResponse, $this->email) extends GoogleClient
                {
                    public function __construct(private array $tokenResponse, private ?string $email)
                    {
                        parent::__construct();
                    }

                    public function fetchAccessTokenWithAuthCode($code, $codeVerifier = null)
                    {
                        return $this->tokenResponse;
                    }

                    public function execute($request, $expectedClass = null)
                    {
                        // The only call made with this client is users.getProfile.
                        return new Profile(['emailAddress' => $this->email]);
                    }
                };
            }
        });
    }
}

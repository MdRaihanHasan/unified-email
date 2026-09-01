<?php

namespace App\Mail\Providers;

use App\Enums\MoveAction;
use App\Mail\Contracts\MailboxProvider;
use App\Mail\Data\ChangeSet;
use App\Mail\Data\FlagChange;
use App\Mail\Data\MessageBody;
use App\Mail\Data\MessagePage;
use App\Mail\Data\OutboundDraft;
use App\Mail\Data\SendResult;
use App\Mail\Data\SyncCursor;
use App\Mail\Exceptions\AuthenticationFailedException;
use App\Models\Folder;
use App\Models\MailAccount;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\AuthorizationCodeContext;
use Psr\Http\Message\StreamInterface;

/**
 * Outlook.com / Microsoft 365 mailbox over Microsoft Graph.
 *
 * Registered against the /common authority with delegated Mail.ReadWrite,
 * Mail.Send, offline_access and User.Read. A single self-consenting user needs no
 * publisher verification — just one pass through the "unverified publisher" screen.
 *
 * Incremental sync is /messages/delta with a stored deltaLink. Graph drops old
 * delta tokens from its cache and answers syncStateNotFound, which must surface as
 * CursorInvalidException.
 *
 * Change-notification subscriptions are deliberately not used: they need a public
 * HTTPS endpoint, and Graph cannot maintain /me/messages subscriptions for consumer
 * accounts without a live user token. Polling keeps every connection outbound.
 */
class GraphProvider implements MailboxProvider
{
    use PendingImplementation;

    public function client(MailAccount $account): GraphServiceClient
    {
        $credentials = $account->credentials ?? [];

        if (empty($credentials['refresh_token'])) {
            throw new AuthenticationFailedException("Account {$account->email} has no refresh token.");
        }

        $context = new AuthorizationCodeContext(
            tenantId: (string) config('mail_providers.microsoft.tenant'),
            clientId: (string) config('mail_providers.microsoft.client_id'),
            clientSecret: (string) config('mail_providers.microsoft.client_secret'),
            authCode: '',
            redirectUri: (string) config('mail_providers.microsoft.redirect_uri'),
        );

        $context->setRefreshToken($credentials['refresh_token']);

        return new GraphServiceClient($context, config('mail_providers.microsoft.scopes'));
    }

    public function verify(MailAccount $account): void
    {
        $this->pending(__FUNCTION__, 'Phase 1');
    }

    public function listFolders(MailAccount $account): array
    {
        $this->pending(__FUNCTION__, 'Phase 1');
    }

    public function fetchPage(MailAccount $account, Folder $folder, ?string $cursor = null): MessagePage
    {
        $this->pending(__FUNCTION__, 'Phase 1');
    }

    public function fetchChanges(MailAccount $account, SyncCursor $cursor): ChangeSet
    {
        // GET {deltaLink}; on syncStateNotFound throw CursorInvalidException.
        $this->pending(__FUNCTION__, 'Phase 1');
    }

    public function currentCursor(MailAccount $account): SyncCursor
    {
        $this->pending(__FUNCTION__, 'Phase 1');
    }

    public function fetchBody(MailAccount $account, string $providerMessageId): MessageBody
    {
        $this->pending(__FUNCTION__, 'Phase 1');
    }

    public function downloadAttachment(
        MailAccount $account,
        string $providerMessageId,
        string $attachmentRemoteId,
    ): StreamInterface {
        $this->pending(__FUNCTION__, 'Phase 3');
    }

    public function send(MailAccount $account, OutboundDraft $draft): SendResult
    {
        // Replies go through createReply so Graph sets In-Reply-To/References itself;
        // new mail goes through sendMail.
        $this->pending(__FUNCTION__, 'Phase 3');
    }

    public function applyFlags(MailAccount $account, array $providerMessageIds, FlagChange $change): void
    {
        // PATCH /me/messages/{id} with isRead / flag.
        $this->pending(__FUNCTION__, 'Phase 1');
    }

    public function move(MailAccount $account, array $providerMessageIds, Folder $destination): void
    {
        $this->pending(__FUNCTION__, 'Phase 4');
    }

    public function applyMove(MailAccount $account, array $providerMessageIds, MoveAction $action): void
    {
        $this->pending(__FUNCTION__, 'Phase 4');
    }
}

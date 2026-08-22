<?php

namespace App\Mail\Providers;

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
use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Psr\Http\Message\StreamInterface;

/**
 * Google Workspace mailbox over the Gmail API.
 *
 * Auth is an OAuth app whose consent screen is set to Internal, which is what
 * exempts this from CASA verification, from the 7-day refresh-token revocation
 * that applies to External+Testing apps, and from the 100-test-user cap. See
 * docs/provider-setup.md.
 *
 * Incremental sync is users.history.list against a stored historyId. Gmail expires
 * old historyIds and answers 404 once ours ages out — that must surface as
 * CursorInvalidException so the caller falls back to a full resync.
 *
 * Folders here are labels, so one message reports several folder ids at once.
 */
class GmailApiProvider implements MailboxProvider
{
    use PendingImplementation;

    private const SCOPES = [
        Gmail::GMAIL_MODIFY,
        Gmail::GMAIL_SEND,
        Gmail::GMAIL_LABELS,
    ];

    public function client(MailAccount $account): Gmail
    {
        $credentials = $account->credentials ?? [];

        if (empty($credentials['refresh_token'])) {
            throw new AuthenticationFailedException("Account {$account->email} has no refresh token.");
        }

        $client = new GoogleClient;
        $client->setClientId((string) config('mail_providers.google.client_id'));
        $client->setClientSecret((string) config('mail_providers.google.client_secret'));
        $client->setScopes(self::SCOPES);
        $client->setAccessType('offline');
        $client->refreshToken($credentials['refresh_token']);

        return new Gmail($client);
    }

    public function verify(MailAccount $account): void
    {
        $this->pending(__FUNCTION__, 'Phase 2');
    }

    public function listFolders(MailAccount $account): array
    {
        $this->pending(__FUNCTION__, 'Phase 2');
    }

    public function fetchPage(MailAccount $account, Folder $folder, ?string $cursor = null): MessagePage
    {
        $this->pending(__FUNCTION__, 'Phase 2');
    }

    public function fetchChanges(MailAccount $account, SyncCursor $cursor): ChangeSet
    {
        // users.history.list(startHistoryId: $cursor->get('historyId'))
        // A 404 here means the historyId aged out -> throw CursorInvalidException.
        $this->pending(__FUNCTION__, 'Phase 2');
    }

    public function currentCursor(MailAccount $account): SyncCursor
    {
        $this->pending(__FUNCTION__, 'Phase 2');
    }

    public function fetchBody(MailAccount $account, string $providerMessageId): MessageBody
    {
        $this->pending(__FUNCTION__, 'Phase 2');
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
        // Build RFC 5322 with symfony/mime, base64url it, and pass threadId so Gmail
        // files the reply on the existing thread. Gmail appends to Sent itself.
        $this->pending(__FUNCTION__, 'Phase 3');
    }

    public function applyFlags(MailAccount $account, array $providerMessageIds, FlagChange $change): void
    {
        // Flags are labels: UNREAD and STARRED via messages.batchModify.
        $this->pending(__FUNCTION__, 'Phase 2');
    }

    public function move(MailAccount $account, array $providerMessageIds, Folder $destination): void
    {
        $this->pending(__FUNCTION__, 'Phase 4');
    }
}

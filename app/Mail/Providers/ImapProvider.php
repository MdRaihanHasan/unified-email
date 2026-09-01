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
use Psr\Http\Message\StreamInterface;
use Webklex\PHPIMAP\Client as ImapClient;
use Webklex\PHPIMAP\ClientManager;

/**
 * Personal @gmail.com (and any other plain IMAP host) over IMAP/SMTP.
 *
 * Auth is a Google app password, not OAuth. A personal Gmail account sits outside
 * the Workspace org, so the Internal OAuth app cannot authorize it, and both
 * External routes are dead ends: Testing mode revokes refresh tokens after 7 days,
 * and Production with a restricted scope requires recurring paid CASA assessment.
 * App passwords still work in 2026; they need 2FA and are revoked if the account
 * password changes.
 *
 * Real-time here is IMAP IDLE (see MailIdleCommand) rather than polling, which is
 * both cheaper and closer to instant. Gmail drops an idle connection at roughly 29
 * minutes, so the daemon re-issues IDLE well before that.
 *
 * Gmail's IMAP quirks that matter:
 *   - folders are labels: "[Gmail]/Sent Mail", "[Gmail]/All Mail", "[Gmail]/Trash"
 *   - "All Mail" mirrors everything else, so backfilling it doubles the work
 *   - smtp.gmail.com copies sent mail to Sent by itself; APPENDing again duplicates
 */
class ImapProvider implements MailboxProvider
{
    use PendingImplementation;

    public function __construct(private readonly ClientManager $clientManager) {}

    public function client(MailAccount $account): ImapClient
    {
        $credentials = $account->credentials ?? [];

        if (empty($credentials['password'])) {
            throw new AuthenticationFailedException("Account {$account->email} has no app password.");
        }

        return $this->clientManager->make([
            'host' => $credentials['imap_host'] ?? 'imap.gmail.com',
            'port' => $credentials['imap_port'] ?? 993,
            'encryption' => $credentials['imap_encryption'] ?? 'ssl',
            'validate_cert' => true,
            'username' => $credentials['username'] ?? $account->email,
            'password' => $credentials['password'],
            'protocol' => 'imap',
        ]);
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
        // Compare stored UIDVALIDITY first: if the server's has changed, every UID we
        // hold now points at something else -> CursorInvalidException.
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
        // SMTP submit only. Do not APPEND to Sent for Gmail — it saves the copy itself.
        $this->pending(__FUNCTION__, 'Phase 3');
    }

    public function applyFlags(MailAccount $account, array $providerMessageIds, FlagChange $change): void
    {
        // IMAP flags: \Seen and \Flagged.
        $this->pending(__FUNCTION__, 'Phase 2');
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

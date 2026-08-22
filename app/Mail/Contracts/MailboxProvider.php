<?php

namespace App\Mail\Contracts;

use App\Mail\Data\ChangeSet;
use App\Mail\Data\FlagChange;
use App\Mail\Data\MessageBody;
use App\Mail\Data\MessagePage;
use App\Mail\Data\OutboundDraft;
use App\Mail\Data\RemoteFolder;
use App\Mail\Data\SendResult;
use App\Mail\Data\SyncCursor;
use App\Mail\Exceptions\CursorInvalidException;
use App\Models\Folder;
use App\Models\MailAccount;
use Psr\Http\Message\StreamInterface;

/**
 * One mailbox, whatever protocol is behind it.
 *
 * Three implementations sit behind this: Gmail API, IMAP/SMTP and Microsoft Graph.
 * The abstraction is not here for vendor independence — it is here so that three
 * genuinely different protocols can feed one inbox. Everything provider-specific
 * (Gmail's labels-as-folders, Graph's createReply, IMAP's UID bookkeeping) stops
 * at this boundary.
 */
interface MailboxProvider
{
    /** Verify credentials and resolve the account's own address. Throws AuthenticationFailedException. */
    public function verify(MailAccount $account): void;

    /** @return list<RemoteFolder> */
    public function listFolders(MailAccount $account): array;

    /**
     * One page of a backfill walk, newest first. $cursor is this provider's own
     * page token, opaque to callers; null starts at the beginning.
     */
    public function fetchPage(MailAccount $account, Folder $folder, ?string $cursor = null): MessagePage;

    /**
     * Everything that changed since $cursor.
     *
     * @throws CursorInvalidException when the cursor aged out
     *                                and the caller must fall back to a full resync.
     */
    public function fetchChanges(MailAccount $account, SyncCursor $cursor): ChangeSet;

    /** The current sync position, for an account that has no cursor yet. */
    public function currentCursor(MailAccount $account): SyncCursor;

    /** Bodies are fetched separately from headers so list views stay cheap. */
    public function fetchBody(MailAccount $account, string $providerMessageId): MessageBody;

    public function downloadAttachment(
        MailAccount $account,
        string $providerMessageId,
        string $attachmentRemoteId,
    ): StreamInterface;

    public function send(MailAccount $account, OutboundDraft $draft): SendResult;

    /** @param  list<string>  $providerMessageIds */
    public function applyFlags(MailAccount $account, array $providerMessageIds, FlagChange $change): void;

    /** @param  list<string>  $providerMessageIds */
    public function move(MailAccount $account, array $providerMessageIds, Folder $destination): void;
}

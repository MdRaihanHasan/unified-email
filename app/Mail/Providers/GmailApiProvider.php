<?php

namespace App\Mail\Providers;

use App\Enums\FolderRole;
use App\Mail\Contracts\MailboxProvider;
use App\Mail\Data\ChangeSet;
use App\Mail\Data\FlagChange;
use App\Mail\Data\MessageBody;
use App\Mail\Data\MessagePage;
use App\Mail\Data\MessageUpdate;
use App\Mail\Data\OutboundDraft;
use App\Mail\Data\RemoteFolder;
use App\Mail\Data\RemoteMessage;
use App\Mail\Data\SendResult;
use App\Mail\Data\SyncCursor;
use App\Mail\Exceptions\AuthenticationFailedException;
use App\Mail\Exceptions\CursorInvalidException;
use App\Mail\Providers\Gmail\ClientFactory;
use App\Mail\Providers\Gmail\MessageParser;
use App\Mail\Support\MimeBuilder;
use App\Models\Folder;
use App\Models\MailAccount;
use Closure;
use Google\Service\Exception as GoogleException;
use Google\Service\Gmail;
use Google\Service\Gmail\BatchModifyMessagesRequest;
use Google\Service\Gmail\Message as GmailMessage;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Every Gmail mailbox — personal and Workspace alike — over the Gmail API.
 *
 * One OAuth client covers both, provided its consent screen is published rather
 * than left in Testing (see config/mail_providers.php for why).
 *
 * Two Gmail traits shape everything here. Flags are labels: a message is unread
 * because it carries UNREAD. And folders are labels too, so one message reports
 * several at once — which is why message_folders is a pivot rather than a column.
 *
 * Incremental sync is users.history.list against a stored historyId. Gmail expires
 * old ids and answers 404, which surfaces as CursorInvalidException so the caller
 * falls back to a full resync.
 */
class GmailApiProvider implements MailboxProvider
{
    /** Gmail's own label ids for the places mail actually lives. */
    private const ROLES = [
        'INBOX' => FolderRole::Inbox,
        'SENT' => FolderRole::Sent,
        'DRAFT' => FolderRole::Drafts,
        'TRASH' => FolderRole::Trash,
        'SPAM' => FolderRole::Junk,
    ];

    /** @var array<int, Gmail> one client per account, reused across calls */
    private array $clients = [];

    public function __construct(
        private readonly MessageParser $parser,
        private readonly MimeBuilder $mime,
        private readonly ClientFactory $clients_,
    ) {}

    public function verify(MailAccount $account): void
    {
        $this->call(fn () => $this->gmail($account)->users->getProfile('me'));
    }

    public function listFolders(MailAccount $account): array
    {
        $labels = $this->call(fn () => $this->gmail($account)->users_labels->listUsersLabels('me'))->getLabels();
        $folders = [];

        foreach ($labels as $label) {
            $id = $label->getId();

            $folders[] = new RemoteFolder(
                remoteId: $id,
                name: $label->getName(),
                role: self::ROLES[$id] ?? FolderRole::Custom,
                path: $label->getName(),
                // Every Gmail folder is a label; that is the whole point.
                isLabel: true,
                // Flags and Gmail's tab categories are labels too, and walking them
                // would re-cover mail INBOX and SENT already gave us.
                isSelectable: ! in_array($id, MessageParser::NON_FOLDER_LABELS, true),
            );
        }

        return $folders;
    }

    public function fetchPage(MailAccount $account, Folder $folder, ?string $cursor = null): MessagePage
    {
        $days = (int) config('mail_providers.sync.backfill_days');

        $listing = $this->call(fn () => $this->gmail($account)->users_messages->listUsersMessages('me', [
            'labelIds' => [$folder->remote_id],
            'maxResults' => (int) config('mail_providers.sync.backfill_page_size'),
            'pageToken' => $cursor,
            // Gmail's own search does the date window, so we never page through
            // years of history to reach the cutoff.
            'q' => $days > 0 ? 'after:'.now()->subDays($days)->format('Y/m/d') : null,
        ]));

        $messages = [];

        foreach ($listing->getMessages() ?? [] as $stub) {
            $messages[] = $this->fetch($account, $stub->getId());
        }

        return new MessagePage(
            messages: array_values(array_filter($messages)),
            nextCursor: $listing->getNextPageToken() ?: null,
        );
    }

    public function fetchChanges(MailAccount $account, SyncCursor $cursor): ChangeSet
    {
        $startHistoryId = $cursor->get('historyId');

        if (blank($startHistoryId)) {
            throw new CursorInvalidException('No historyId stored for '.$account->email);
        }

        $created = [];
        $deleted = [];
        $touched = [];
        $latest = (string) $startHistoryId;
        $pageToken = null;

        do {
            $response = $this->history($account, (string) $startHistoryId, $pageToken);

            foreach ($response->getHistory() ?? [] as $entry) {
                foreach ($entry->getMessagesAdded() ?? [] as $added) {
                    $message = $this->fetch($account, $added->getMessage()->getId());

                    if ($message !== null) {
                        $created[$message->providerMessageId] = $message;
                    }
                }

                foreach ($entry->getMessagesDeleted() ?? [] as $removed) {
                    $deleted[] = $removed->getMessage()->getId();
                }

                // Gmail reports which labels changed, not the resulting state, so
                // the touched message is re-read rather than diffed by hand.
                foreach ([...($entry->getLabelsAdded() ?? []), ...($entry->getLabelsRemoved() ?? [])] as $change) {
                    $touched[$change->getMessage()->getId()] = true;
                }
            }

            $latest = $response->getHistoryId() ?: $latest;
            $pageToken = $response->getNextPageToken() ?: null;
        } while ($pageToken !== null);

        $updates = [];

        foreach (array_keys($touched) as $id) {
            // A message deleted in the same batch cannot be read back.
            if (isset($created[$id]) || in_array($id, $deleted, true)) {
                continue;
            }

            $update = $this->flagsOf($account, $id);

            if ($update !== null) {
                $updates[] = $update;
            }
        }

        return new ChangeSet(
            created: array_values($created),
            updated: $updates,
            deletedIds: array_values(array_unique($deleted)),
            cursor: new SyncCursor(['historyId' => $latest]),
        );
    }

    public function currentCursor(MailAccount $account): SyncCursor
    {
        $profile = $this->call(fn () => $this->gmail($account)->users->getProfile('me'));

        return new SyncCursor(['historyId' => (string) $profile->getHistoryId()]);
    }

    public function fetchBody(MailAccount $account, string $providerMessageId): MessageBody
    {
        $message = $this->fetch($account, $providerMessageId);

        if ($message === null) {
            return new MessageBody;
        }

        return new MessageBody($message->bodyHtml, $message->bodyText, $message->attachments);
    }

    public function downloadAttachment(
        MailAccount $account,
        string $providerMessageId,
        string $attachmentRemoteId,
    ): StreamInterface {
        $attachment = $this->call(fn () => $this->gmail($account)
            ->users_messages_attachments
            ->get('me', $providerMessageId, $attachmentRemoteId));

        $data = base64_decode(strtr((string) $attachment->getData(), '-_', '+/'), false);

        return Utils::streamFor($data === false ? '' : $data);
    }

    public function send(MailAccount $account, OutboundDraft $draft): SendResult
    {
        $messageId = $this->mime->generateMessageId($account);
        $raw = $this->mime->raw($account, $draft, $messageId);

        $payload = new GmailMessage;
        $payload->setRaw(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='));

        // threadId is what files a reply onto the existing conversation. Gmail
        // rejects it unless the References headers agree, which MimeBuilder sets.
        if ($draft->providerThreadId !== null) {
            $payload->setThreadId($draft->providerThreadId);
        }

        $sent = $this->call(fn () => $this->gmail($account)->users_messages->send('me', $payload));

        return new SendResult(
            providerMessageId: $sent->getId(),
            providerThreadId: $sent->getThreadId(),
            rfc822MessageId: $messageId,
            sentAt: new \DateTimeImmutable,
        );
    }

    public function applyFlags(MailAccount $account, array $providerMessageIds, FlagChange $change): void
    {
        if ($providerMessageIds === [] || $change->isEmpty()) {
            return;
        }

        $add = [];
        $remove = [];

        // Read and starred are labels, so a flag change is a label change.
        if ($change->isRead !== null) {
            $change->isRead ? $remove[] = 'UNREAD' : $add[] = 'UNREAD';
        }

        if ($change->isStarred !== null) {
            $change->isStarred ? $add[] = 'STARRED' : $remove[] = 'STARRED';
        }

        $this->batchModify($account, $providerMessageIds, $add, $remove);
    }

    public function move(MailAccount $account, array $providerMessageIds, Folder $destination): void
    {
        if ($providerMessageIds === []) {
            return;
        }

        // Moving out of the inbox is removing INBOX, not setting a folder field.
        $this->batchModify($account, $providerMessageIds, [$destination->remote_id], ['INBOX']);
    }

    // ---- internals -------------------------------------------------------

    private function batchModify(MailAccount $account, array $ids, array $add, array $remove): void
    {
        // batchModify caps at 1000 ids per call.
        foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
            $request = new BatchModifyMessagesRequest;
            $request->setIds($chunk);

            if ($add !== []) {
                $request->setAddLabelIds($add);
            }

            if ($remove !== []) {
                $request->setRemoveLabelIds($remove);
            }

            $this->call(fn () => $this->gmail($account)->users_messages->batchModify('me', $request));
        }
    }

    private function fetch(MailAccount $account, string $id): ?RemoteMessage
    {
        try {
            $message = $this->call(fn () => $this->gmail($account)
                ->users_messages->get('me', $id, ['format' => 'full']));
        } catch (GoogleException $e) {
            // A message deleted between the listing and this read is normal.
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        return $this->parser->parse($message);
    }

    /** Current label state for one message, as a partial update. */
    private function flagsOf(MailAccount $account, string $id): ?MessageUpdate
    {
        try {
            $message = $this->call(fn () => $this->gmail($account)
                ->users_messages->get('me', $id, ['format' => 'minimal']));
        } catch (GoogleException $e) {
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        $labels = $message->getLabelIds() ?? [];

        return new MessageUpdate(
            providerMessageId: $id,
            isRead: ! in_array('UNREAD', $labels, true),
            isStarred: in_array('STARRED', $labels, true),
            folderRemoteIds: $this->parser->folderLabels($labels),
        );
    }

    private function history(MailAccount $account, string $startHistoryId, ?string $pageToken)
    {
        try {
            return $this->gmail($account)->users_history->listUsersHistory('me', array_filter([
                'startHistoryId' => $startHistoryId,
                'pageToken' => $pageToken,
            ]));
        } catch (GoogleException $e) {
            // 404 means the id aged out of Gmail's window. Nothing can be recovered
            // incrementally from here, so say so plainly and let the caller resync.
            if ($e->getCode() === 404) {
                throw new CursorInvalidException(
                    "Gmail no longer knows historyId {$startHistoryId} for {$account->email}.",
                );
            }

            throw $this->translate($e, $account);
        }
    }

    /**
     * Run a Gmail call, translating the failures that mean something to us.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function call(Closure $call): mixed
    {
        try {
            return $call();
        } catch (GoogleException $e) {
            throw $this->translate($e);
        }
    }

    private function translate(GoogleException $e, ?MailAccount $account = null): \Throwable
    {
        $where = $account === null ? '' : ' for '.$account->email;

        // 401 is a dead token; 403 with this reason is a revoked or suspended grant.
        // Neither can be retried into working, so they must not look like a blip.
        if ($e->getCode() === 401 || str_contains($e->getMessage(), 'invalid_grant')) {
            return new AuthenticationFailedException("Google rejected our credentials{$where}.", 0, $e);
        }

        return $e;
    }

    private function gmail(MailAccount $account): Gmail
    {
        // One client per account, reused: the library caches the access token on it,
        // so a fresh client would re-exchange the refresh token on every call.
        return $this->clients[$account->id] ??= new Gmail($this->clients_->forAccount($account));
    }
}

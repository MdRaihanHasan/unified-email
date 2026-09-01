<?php

namespace Tests\Support;

use App\Enums\MoveAction;
use App\Mail\Contracts\MailboxProvider;
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
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * In-memory MailboxProvider, so the sync pipeline can be exercised end to end
 * without credentials or a network. Scripted rather than clever: each test sets up
 * exactly the folders, pages and change sets it wants to see handled.
 */
class FakeProvider implements MailboxProvider
{
    /** @var list<RemoteFolder> */
    public array $folders = [];

    /** @var array<string, list<MessagePage>> folder remote id => pages, in order */
    public array $pages = [];

    /** @var list<ChangeSet> consumed one per fetchChanges() call */
    public array $changeSets = [];

    public bool $cursorExpired = false;

    public SyncCursor $cursor;

    /** @var list<array{ids: list<string>, change: FlagChange}> */
    public array $appliedFlags = [];

    /** @var list<OutboundDraft> */
    public array $sent = [];

    /** Remote ids handed to downloadAttachment, so a test can count provider hits. */
    public array $downloadedAttachments = [];

    /** @var list<array{ids: list<string>, action: MoveAction}> */
    public array $appliedMoves = [];

    public ?\Throwable $moveFailure = null;

    public ?\Throwable $flagFailure = null;

    public ?\Throwable $changesFailure = null;

    public ?\Throwable $foldersFailure = null;

    /** @var array<string, int> folder remote id => pages already served */
    private array $served = [];

    public function __construct()
    {
        $this->cursor = new SyncCursor(['token' => 'initial']);
    }

    public function verify(MailAccount $account): void {}

    public function listFolders(MailAccount $account): array
    {
        if ($this->foldersFailure !== null) {
            throw $this->foldersFailure;
        }

        return $this->folders;
    }

    public function fetchPage(MailAccount $account, Folder $folder, ?string $cursor = null): MessagePage
    {
        $index = $this->served[$folder->remote_id] ?? 0;
        $this->served[$folder->remote_id] = $index + 1;

        return $this->pages[$folder->remote_id][$index] ?? new MessagePage([]);
    }

    public function fetchChanges(MailAccount $account, SyncCursor $cursor): ChangeSet
    {
        if ($this->cursorExpired) {
            throw new CursorInvalidException('Fake cursor expired.');
        }

        if ($this->changesFailure !== null) {
            throw $this->changesFailure;
        }

        return array_shift($this->changeSets) ?? new ChangeSet(cursor: $cursor);
    }

    public function currentCursor(MailAccount $account): SyncCursor
    {
        return $this->cursor;
    }

    public function fetchBody(MailAccount $account, string $providerMessageId): MessageBody
    {
        return new MessageBody('<p>body</p>', 'body');
    }

    public function downloadAttachment(MailAccount $account, string $providerMessageId, string $attachmentRemoteId): StreamInterface
    {
        $this->downloadedAttachments[] = $attachmentRemoteId;

        return Utils::streamFor('fake-bytes-of-'.$attachmentRemoteId);
    }

    public function send(MailAccount $account, OutboundDraft $draft): SendResult
    {
        $this->sent[] = $draft;

        return new SendResult('sent-'.count($this->sent));
    }

    public function applyFlags(MailAccount $account, array $providerMessageIds, FlagChange $change): void
    {
        if ($this->flagFailure !== null) {
            throw $this->flagFailure;
        }

        $this->appliedFlags[] = ['ids' => $providerMessageIds, 'change' => $change];
    }

    public function move(MailAccount $account, array $providerMessageIds, Folder $destination): void {}

    public function applyMove(MailAccount $account, array $providerMessageIds, MoveAction $action): void
    {
        if ($this->moveFailure !== null) {
            throw $this->moveFailure;
        }

        $this->appliedMoves[] = ['ids' => $providerMessageIds, 'action' => $action];
    }
}

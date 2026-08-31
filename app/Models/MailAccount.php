<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\FolderRole;
use App\Enums\Provider;
use App\Mail\Contracts\MailboxProvider;
use App\Mail\ProviderManager;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailAccount extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'status' => AccountStatus::class,

            // Refresh tokens and app passwords. Encrypted with APP_KEY, so losing
            // APP_KEY means losing every connected account — back it up separately.
            'credentials' => 'encrypted:array',

            'sync_cursor' => 'array',
            'backfill_done_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function outboundMessages(): HasMany
    {
        return $this->hasMany(OutboundMessage::class);
    }

    public function inbox(): ?Folder
    {
        return $this->folders()->where('role', FolderRole::Inbox)->first();
    }

    public function driver(): MailboxProvider
    {
        return app(ProviderManager::class)->for($this);
    }

    public function hasFinishedBackfill(): bool
    {
        return $this->backfill_done_at !== null;
    }

    /**
     * Sync has not run recently enough. Watched by a scheduled command, because a
     * silently stalled account is the failure mode this design has to guard against.
     *
     * An account still doing its first import is NOT stale — it is new, and has not
     * had a chance yet. Reporting it as behind produced two banners that contradicted
     * each other ("still importing" beside "last synced never") on a mailbox that had
     * just been connected. Progress there is judged by importStalled() instead.
     */
    public function isStale(int $minutes = 15): bool
    {
        if (! $this->status->shouldSync() || ! $this->hasFinishedBackfill()) {
            return false;
        }

        return $this->last_synced_at === null
            || $this->last_synced_at->isBefore(now()->subMinutes($minutes));
    }

    /**
     * The first thing BackfillJob does is record the mailbox's folders, so their
     * absence means the job has not run at all — not that it is slow.
     */
    public function hasStartedImport(): bool
    {
        return $this->folders()->exists();
    }

    /**
     * Connected, but nothing has run.
     *
     * Almost always one thing: the queue worker is not up, so the job sits in Redis
     * forever. Worth saying out loud, because there is nothing in the UI or the logs
     * to suggest it — the account looks connected and simply never fills.
     */
    public function importStalled(int $minutes = 5): bool
    {
        return $this->status->shouldSync()
            && ! $this->hasFinishedBackfill()
            && ! $this->hasStartedImport()
            && $this->created_at !== null
            && $this->created_at->isBefore(now()->subMinutes($minutes));
    }

    /**
     * How far the first import has got, so the banner can show movement rather than
     * an indefinite "still importing".
     *
     * @return array{folders_done: int, folders_total: int, messages: int}
     */
    public function importProgress(): array
    {
        $folders = $this->folders()->get(['id', 'role', 'is_selectable', 'backfill_done_at']);

        return [
            'folders_done' => $folders->whereNotNull('backfill_done_at')->count(),
            // Only the folders that will actually be walked; counting the rest makes
            // the total look wrong and the progress look stuck.
            'folders_total' => $folders->filter(fn (Folder $folder) => $folder->shouldBackfill())->count(),
            'messages' => $this->messages()->count(),
        ];
    }
}

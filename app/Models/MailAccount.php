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
     */
    public function isStale(int $minutes = 15): bool
    {
        return $this->status->shouldSync()
            && ($this->last_synced_at === null || $this->last_synced_at->isBefore(now()->subMinutes($minutes)));
    }
}

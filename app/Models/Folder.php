<?php

namespace App\Models;

use App\Enums\FolderRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Folder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['role' => FolderRole::class];
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(Message::class, 'message_folders');
    }

    /**
     * Gmail's "All Mail" mirrors every other folder, so backfilling it duplicates
     * the entire mailbox's worth of work for nothing.
     *
     * Trash and Junk ARE walked: incremental sync imported their mail anyway
     * (history.list has no label filter), so skipping them here only meant the
     * app's copy depended on when a message arrived. With both imported, the Spam
     * and Trash views are complete and a restore in Gmail syncs back cleanly —
     * the unread and All-mail counts exclude them instead.
     */
    public function shouldBackfill(): bool
    {
        return $this->is_selectable
            && $this->role !== FolderRole::AllMail;
    }
}

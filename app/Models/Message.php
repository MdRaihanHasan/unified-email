<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'references_ids' => 'array',
            'from_addr' => 'array',
            'to_addrs' => 'array',
            'cc_addrs' => 'array',
            'bcc_addrs' => 'array',
            'reply_to' => 'array',
            'headers' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'is_read' => 'boolean',
            'is_starred' => 'boolean',
            'is_draft' => 'boolean',
            'is_answered' => 'boolean',
            'has_attachments' => 'boolean',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    public function folders(): BelongsToMany
    {
        return $this->belongsToMany(Folder::class, 'message_folders');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'message_tag');
    }

    /**
     * The References chain to put on a reply to this message: everything this
     * message already referenced, plus this message itself.
     *
     * @return list<string>
     */
    public function replyReferences(): array
    {
        $references = $this->references_ids ?? [];

        if ($this->rfc822_message_id !== null) {
            $references[] = $this->rfc822_message_id;
        }

        return array_values(array_unique($references));
    }
}

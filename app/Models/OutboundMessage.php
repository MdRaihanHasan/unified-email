<?php

namespace App\Models;

use App\Enums\OutboundStatus;
use App\Enums\OutboundType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboundMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => OutboundType::class,
            'status' => OutboundStatus::class,
            'to_addrs' => 'array',
            'cc_addrs' => 'array',
            'bcc_addrs' => 'array',
            'attachments' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    public function inReplyToMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'in_reply_to_message_id');
    }
}

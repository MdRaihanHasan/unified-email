<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_inline' => 'boolean',
            'downloaded_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isCached(): bool
    {
        return $this->disk_path !== null;
    }
}

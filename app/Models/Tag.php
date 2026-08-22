<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $guarded = ['id'];

    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(Message::class, 'message_tag');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Thread extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'first_message_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Strip reply/forward prefixes so subject-based threading can compare like with like. */
    public static function normalizeSubject(?string $subject): string
    {
        $subject = trim((string) $subject);

        do {
            $previous = $subject;
            $subject = preg_replace('/^\s*(re|aw|fwd?|fw|sv|vs|antw)\s*(\[\d+\])?\s*:\s*/iu', '', $subject) ?? $subject;
        } while ($subject !== $previous);

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $subject) ?? $subject));
    }
}

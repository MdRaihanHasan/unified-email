<?php

namespace App\Models;

use App\Enums\FolderRole;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Filter to one of the inbox's saved views.
     *
     * "inbox" and "sent" go through the folder pivot rather than a column, because
     * for Gmail a message's folders are labels and a thread can legitimately have
     * messages in several at once.
     */
    public function scopeInView(Builder $query, string $view): Builder
    {
        return match ($view) {
            'unread' => $query->where('unread_count', '>', 0),
            'starred' => $query->where('is_starred', true),
            'inbox' => $query->whereHas('messages.folders', fn (Builder $q) => $q->where('role', FolderRole::Inbox)),
            'sent' => $query->whereHas('messages.folders', fn (Builder $q) => $q->where('role', FolderRole::Sent)),
            'junk' => $query->whereHas('messages.folders', fn (Builder $q) => $q->where('role', FolderRole::Junk)),
            'trash' => $query->whereHas('messages.folders', fn (Builder $q) => $q->where('role', FolderRole::Trash)),
            // "All mail" matches Gmail's: everything except what lives only in
            // Trash or Spam. A message with no folders at all (archived) counts.
            'all' => $query->whereHas('messages', fn (Builder $q) => $q
                ->whereDoesntHave('folders', fn (Builder $f) => $f
                    ->whereIn('role', [FolderRole::Trash, FolderRole::Junk]))),
            default => $query,
        };
    }

    public function scopeForAccount(Builder $query, ?int $accountId): Builder
    {
        return $accountId === null
            ? $query
            : $query->whereHas('messages', fn (Builder $q) => $q->where('mail_account_id', $accountId));
    }

    /**
     * Full-text search across the thread's messages.
     *
     * websearch_to_tsquery rather than to_tsquery: it accepts whatever a person
     * types — quoted phrases, stray operators, unbalanced quotes — instead of
     * raising a syntax error on the query.
     */
    public function scopeMatching(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->whereHas('messages', fn (Builder $q) => $q
            ->whereRaw("search_vector @@ websearch_to_tsquery('simple', ?)", [$term]));
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

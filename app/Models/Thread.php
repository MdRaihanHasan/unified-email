<?php

namespace App\Models;

use App\Enums\FolderRole;
use App\Mail\Support\SearchQueryParser;
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
     * Search across the thread's messages: the Gmail operator grammar plus
     * full text, all applied to ONE message — "from:cloudflare is:unread" means
     * a message that is both, not a thread that has each somewhere.
     *
     * websearch_to_tsquery rather than to_tsquery for the free text: it accepts
     * whatever a person types — quoted phrases, stray operators, unbalanced
     * quotes — instead of raising a syntax error on the query. The final bare
     * word gets a :* prefix match so "invoic" finds invoices while you type.
     */
    public function scopeMatching(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $parsed = app(SearchQueryParser::class)->parse($term);

        return $query->whereHas('messages', function (Builder $q) use ($parsed) {
            foreach ($parsed['from'] as $needle) {
                $q->where(fn (Builder $w) => $w
                    ->whereRaw("from_addr->>'address' ILIKE ?", ['%'.$needle.'%'])
                    ->orWhereRaw("from_addr->>'name' ILIKE ?", ['%'.$needle.'%']));
            }

            foreach ($parsed['to'] as $needle) {
                $q->whereRaw('to_addrs::text ILIKE ?', ['%'.$needle.'%']);
            }

            foreach ($parsed['subject'] as $needle) {
                $q->where('subject', 'ILIKE', '%'.$needle.'%');
            }

            if ($parsed['has_attachment']) {
                $q->where('has_attachments', true);
            }

            foreach ($parsed['is'] as $state) {
                match ($state) {
                    'unread' => $q->where('is_read', false),
                    'read' => $q->where('is_read', true),
                    'starred' => $q->where('is_starred', true),
                    default => null,
                };
            }

            if ($parsed['account'] !== null) {
                $q->whereHas('mailAccount', fn (Builder $a) => $a
                    ->where(fn (Builder $w) => $w
                        ->where('label', 'ILIKE', '%'.$parsed['account'].'%')
                        ->orWhere('email', 'ILIKE', '%'.$parsed['account'].'%')));
            }

            if ($parsed['before'] !== null) {
                $q->where('received_at', '<', $parsed['before']);
            }

            if ($parsed['after'] !== null) {
                $q->where('received_at', '>=', $parsed['after']);
            }

            if ($parsed['text'] !== '') {
                [$sql, $bindings] = self::tsquery($parsed['text']);
                $q->whereRaw($sql, $bindings);
            }
        });
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function tsquery(string $text): array
    {
        // Prefix-match the final bare word — unless the query carries phrases or
        // exclusions, where rewriting the tail would change what was asked.
        $simple = ! str_contains($text, '"') && ! str_contains($text, '-');

        if ($simple && preg_match('/^(.*?)([\p{L}\p{N}]{2,})$/us', $text, $m)) {
            $head = trim($m[1]);
            $last = $m[2].':*';

            return $head === ''
                ? ["search_vector @@ to_tsquery('simple', ?)", [$last]]
                : ["search_vector @@ (websearch_to_tsquery('simple', ?) && to_tsquery('simple', ?))", [$head, $last]];
        }

        return ["search_vector @@ websearch_to_tsquery('simple', ?)", [$text]];
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

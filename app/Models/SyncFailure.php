<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message the sync could not store — quarantined so it cannot wedge its account.
 *
 * Rows here mean a parser or schema gap: fix the code, delete the row (or run a
 * resync), and the message imports on the next pass. The count is surfaced by
 * mail:status and the accounts page, because a quarantine nobody looks at is just
 * silent data loss with extra steps.
 */
class SyncFailure extends Model
{
    protected $guarded = ['id'];

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    /** Record one failed store, keeping a count when the same message keeps failing. */
    public static function record(MailAccount $account, string $providerMessageId, \Throwable $e): void
    {
        $existing = static::query()
            ->where('mail_account_id', $account->id)
            ->where('provider_message_id', $providerMessageId)
            ->first();

        if ($existing !== null) {
            $existing->update([
                'error' => static::describe($e),
                'occurrences' => $existing->occurrences + 1,
            ]);

            return;
        }

        static::create([
            'mail_account_id' => $account->id,
            'provider_message_id' => $providerMessageId,
            'error' => static::describe($e),
        ]);
    }

    private static function describe(\Throwable $e): string
    {
        return mb_scrub(get_class($e).': '.mb_substr($e->getMessage(), 0, 1000), 'UTF-8');
    }
}

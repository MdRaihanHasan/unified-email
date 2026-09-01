<?php

namespace App\Support;

use App\Models\MailAccount;

/**
 * One hue per connected mailbox, stable everywhere the account appears:
 * sidebar dot, thread-row edge, reading-pane chip, composer From row.
 *
 * The four current mailboxes get their designed colors by name; anything
 * connected later falls back to the same palette cycled by id. Red is absent
 * on purpose — it stays reserved for spam and delete.
 */
class AccountColor
{
    private const NAMED = [
        'bixcel' => '#0EA5E9',
        'oxcel' => '#10B981',
        'dealercore' => '#F59E0B',
        'brokercore' => '#EC4899',
    ];

    private const PALETTE = ['#0EA5E9', '#10B981', '#F59E0B', '#EC4899', '#8B5CF6', '#14B8A6'];

    public static function for(MailAccount $account): string
    {
        $haystack = mb_strtolower($account->label.' '.$account->email);

        foreach (self::NAMED as $name => $color) {
            if (str_contains($haystack, $name)) {
                return $color;
            }
        }

        return self::PALETTE[$account->id % count(self::PALETTE)];
    }
}

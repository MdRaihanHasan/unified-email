<?php

namespace App\Enums;

/**
 * The triage verbs. Each is a change of where a message lives, not of a flag —
 * on Gmail that means label surgery, and locally it means pivot surgery.
 */
enum MoveAction: string
{
    case Archive = 'archive';
    case Trash = 'trash';
    case Spam = 'spam';

    /** Back to the inbox, out of Trash and Spam alike. */
    case Restore = 'restore';

    public function pastTense(): string
    {
        return match ($this) {
            self::Archive => 'Archived',
            self::Trash => 'Moved to trash',
            self::Spam => 'Marked as spam',
            self::Restore => 'Restored to inbox',
        };
    }
}

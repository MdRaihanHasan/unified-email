<?php

namespace App\Enums;

enum FolderRole: string
{
    case Inbox = 'inbox';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Trash = 'trash';
    case Junk = 'junk';
    case Archive = 'archive';

    /** Gmail's "All Mail", which mirrors every other folder and must not be backfilled. */
    case AllMail = 'all_mail';

    case Custom = 'custom';
}

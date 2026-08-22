<?php

namespace App\Enums;

enum Provider: string
{
    /** Google Workspace mailbox via the Gmail API + an Internal OAuth app. */
    case GmailApi = 'gmail_api';

    /** Personal @gmail.com (or any IMAP host) via app password over IMAP/SMTP. */
    case Imap = 'imap';

    /** Outlook.com or Microsoft 365 via Microsoft Graph. */
    case Graph = 'graph';

    public function label(): string
    {
        return match ($this) {
            self::GmailApi => 'Gmail (API)',
            self::Imap => 'IMAP / SMTP',
            self::Graph => 'Outlook (Graph)',
        };
    }

    /**
     * Whether one message can live in several folders at once. Only Gmail's label
     * model allows it, and it is why message_folders is a pivot table.
     */
    public function hasMultiFolderMessages(): bool
    {
        return $this === self::GmailApi;
    }

    /** Whether the provider exposes its own thread identifier we can trust. */
    public function hasNativeThreadId(): bool
    {
        return $this !== self::Imap;
    }

    /**
     * Whether new mail arrives by a held-open connection (IMAP IDLE) rather than
     * by the scheduler polling a delta cursor.
     */
    public function supportsIdle(): bool
    {
        return $this === self::Imap;
    }
}

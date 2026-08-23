<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Google — every Gmail mailbox, personal and Workspace alike
    |---------------------------------------------------------------------------
    | One External OAuth client covers both, as long as its publishing status is
    | "In production". Two things follow from that and only that:
    |
    |   - The 7-day refresh-token revocation applies to "Testing" status only, so
    |     tokens from a published app keep working.
    |   - An unverified app in production still works with restricted scopes. The
    |     cost is a one-time "unverified app" warning per account and a cap of 100
    |     new users, which is irrelevant for a single-user instance — and no CASA
    |     assessment, which would otherwise be a recurring four-figure expense.
    |
    | The cap is permanent on the Cloud project and cannot be reset by issuing a
    | new client id, so it matters only if this ever stops being one person's tool.
    |
    | An "Internal" consent screen avoids the warning screen but authorizes only
    | accounts inside the Workspace org, which would leave personal Gmail out.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),

        // gmail.modify carries read, flags and labels; sending needs its own scope.
        // Deliberately no gmail.labels (that manages label definitions, which we
        // never do) and no https://mail.google.com/ (full IMAP-level access we do
        // not need).
        'scopes' => [
            'https://www.googleapis.com/auth/gmail.modify',
            'https://www.googleapis.com/auth/gmail.send',
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Microsoft (Outlook.com / Microsoft 365, Graph)
    |---------------------------------------------------------------------------
    | Tenant "common" covers both personal Outlook.com and work accounts. A single
    | self-consenting user needs no publisher verification.
    */
    'microsoft' => [
        'client_id' => env('MS_CLIENT_ID'),
        'client_secret' => env('MS_CLIENT_SECRET'),
        'tenant' => env('MS_TENANT', 'common'),
        'redirect_uri' => env('MS_REDIRECT_URI'),
        'scopes' => [
            'https://graph.microsoft.com/Mail.ReadWrite',
            'https://graph.microsoft.com/Mail.Send',
            'https://graph.microsoft.com/User.Read',
            'offline_access',
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Sync
    |---------------------------------------------------------------------------
    */
    'sync' => [
        // How far back the initial backfill reaches. Everything older is pulled
        // later in the background rather than blocking first use.
        'backfill_days' => (int) env('SYNC_BACKFILL_DAYS', 90),

        'backfill_page_size' => (int) env('SYNC_BACKFILL_PAGE_SIZE', 100),

        // An account whose last successful sync is older than this is treated as
        // stalled and surfaced in the UI. Silent staleness is the failure mode
        // this whole design has to defend against.
        'stale_after_minutes' => (int) env('SYNC_STALE_AFTER_MINUTES', 15),

        // Gmail drops an idle IMAP connection at around 29 minutes, so re-issue
        // IDLE comfortably before that.
        'idle_refresh_minutes' => (int) env('SYNC_IDLE_REFRESH_MINUTES', 25),

        'thread_subject_window_days' => (int) env('SYNC_THREAD_WINDOW_DAYS', 30),
    ],

];

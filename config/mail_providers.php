<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Google (Workspace mailbox, Gmail API)
    |---------------------------------------------------------------------------
    | The OAuth consent screen for this client MUST be set to "Internal". That one
    | setting is what exempts us from CASA verification, from the 7-day refresh
    | token revocation, and from the 100-test-user cap. Creating the Cloud project
    | with a Workspace account is required for "Internal" to be offered at all.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
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

<?php

namespace App\Mail\Exceptions;

/**
 * The stored incremental cursor is no longer usable and the only recovery is a
 * full resync. Thrown for:
 *
 *   gmail_api  404 on history.list — the historyId aged out of Gmail's window
 *   graph      syncStateNotFound — the delta token fell out of the token cache
 *   imap       UIDVALIDITY changed — every UID we hold now means something else
 *
 * Left unhandled, an account goes quietly stale with no error anywhere, which is
 * the single easiest way for this design to fail. SyncAccountJob catches this and
 * dispatches a full resync.
 */
class CursorInvalidException extends MailProviderException {}

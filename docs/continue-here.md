# Continue here

Where the project stands and what to do next, written for a session running **on the
server** — where Docker, the real Google credentials and a real mailbox exist. None of
those were available where the code was written, which is why the first task is
verification rather than more building.

---

## The open problem

A mailbox (`itsrahul880@gmail.com`) connected successfully through the OAuth flow, but
no mail arrived. The UI reported both "Still importing history" and "last synced never"
at once.

The banner contradiction is fixed on branch `claude/sync-status-diagnostics` (a fresh
mailbox is no longer called "behind"). **The underlying cause is unknown.**

`last_synced_at` is written in exactly two places — `BackfillJob` when it completes, and
`SyncAccountJob` after a successful pass. "Never" therefore means `BackfillJob` has not
finished a single run.

### Step 1 — one command decides everything

```bash
docker compose exec app php artisan queue:work --once -v
```

Takes exactly one job off the queue and runs it in the foreground. Three outcomes:

| What happens | What it means | What to do |
|---|---|---|
| Returns instantly, nothing processed | The queue is empty — the job was never enqueued | Check `QUEUE_CONNECTION` and that Redis is reachable from the app container |
| Runs, and mail starts importing | The worker container was simply not up | `docker compose ps worker`, `docker compose logs worker`, restart it |
| Prints an exception | The real bug, and the most likely one | Read on |

Then:

```bash
docker compose exec app php artisan mail:status
```

Per mailbox: status, folders known and walked, messages, threads, first-import state,
cursor, last error, plus queue depth. If pending jobs never falls, nothing is consuming
the queue.

### Step 2 — if it threw

The Gmail API path has never run against Google. The parsing and the contract are
covered by fixtures, but no call has been made for real. Likely places to land:

- **`ClientFactory::forAccount()`** — `fetchAccessTokenWithRefreshToken` failing. A
  rejected token raises `AuthenticationFailedException` and marks the account
  `auth_error`, which shows as "Reconnect needed" in the UI.
- **`GmailApiProvider::listFolders()`** — the first real call `BackfillJob` makes.
- **`GmailApiProvider::fetchPage()`** — the `q => after:YYYY/MM/DD` date window, or a
  `format=full` response shaped differently from the fixtures.
- **`MessageParser::parse()`** — a real message with a MIME tree the fixtures do not
  cover. This is the most likely one, and the easiest to fix: add the failing shape to
  `tests/Unit/GmailMessageParserTest.php` and make it pass.

Useful while debugging:

```bash
# Watch a backfill run with full output
docker compose exec app php artisan queue:work --once -v

# Talk to Gmail directly, without the job wrapper
docker compose exec app php artisan tinker
>>> $a = App\Models\MailAccount::first();
>>> $p = $a->driver();
>>> $p->verify($a);                 // does auth work at all?
>>> collect($p->listFolders($a))->pluck('name');
>>> $p->currentCursor($a);
>>> $page = $p->fetchPage($a, $a->folders()->where('role','inbox')->first());
>>> count($page->messages);
>>> $page->messages[0];             // inspect a parsed message

# Start over on one mailbox
>>> App\Jobs\FullResyncJob::dispatch($a);
```

### Step 3 — a trap worth knowing

`BackfillJob` and `SyncAccountJob` use `WithoutOverlapping(...)->dontRelease()`. If a
job dies without releasing its lock — a killed container, say — later dispatches are
**silently discarded** until the lock expires (`expireAfter(1800)`, so 30 minutes). If
jobs seem to vanish with no error, clear the lock rather than waiting:

```bash
docker compose exec app php artisan cache:clear
```

---

## After that works

Rough order of value:

1. **Merge `claude/sync-status-diagnostics`** into master once the real cause is known —
   and fold anything learned into it.
2. **Whatever the first real backfill exposes.** Real mailboxes contain MIME the
   fixtures do not. Add each shape to the parser tests as it turns up.
3. **Attachment download endpoint.** `GmailApiProvider::downloadAttachment()` exists;
   there is no route or UI to reach it, so attachments list but cannot be opened.
4. **Verify sending** end to end against a real mailbox — headers, threading, the Sent
   copy coming back through sync and matching on `rfc822_message_id`.
5. **Archive / delete / move.** They need `provider->move()`, which Gmail implements as
   a label change. The bulk bar and hover actions deliberately omit them today rather
   than shipping buttons that fail.
6. **Microsoft Graph adapter**, if Outlook is still wanted. Needs an Entra app
   registration — see `docs/provider-setup.md`.

## Ground rules

Everything in `CLAUDE.md` applies. The two that matter most here:

- **Unimplemented things throw rather than returning empty data.** Keep it that way; a
  silent empty result looks exactly like a working sync.
- **Drive UI changes in a browser before believing them.** Every UI bug found in this
  project so far came from that and not from assertions.

And report honestly what was checked against a real mailbox versus what was only
reasoned about. That distinction is the whole reason this file exists.

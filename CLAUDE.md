# Unified Mail

A self-hosted, **single-user** unified inbox: Gmail (personal + Workspace) and
later Outlook, in one Laravel app. Not a SaaS — no tenancy, no signup, no billing.

## Run it

```bash
docker compose up -d --build
docker compose exec app php artisan mail:status     # start every debugging session here
docker compose logs -f worker
```

Without Docker: `php artisan serve` (port 8000 — the Google redirect URI depends on
it), plus `queue:work` and `schedule:work` in their own terminals.

Tests need **Postgres**, not SQLite: the schema uses a generated `tsvector` column,
a GIN index and `jsonb`. Create `unified_email_test` and run `php artisan test`.

## How it fits together

```
Vue 3 + Inertia ──► Laravel ──► MailboxProvider ──┬── GmailApiProvider   (implemented)
                                                   ├── GraphProvider      (stubbed)
                                                   └── ImapProvider       (stubbed)
                        │
                   Postgres (source of truth) · Redis (queue) · local disk (attachments)
```

Every provider connection is **outbound** — polling and, for IMAP, IDLE. There are no
webhooks, no public endpoint, no tunnel. That was deliberate: Gmail Pub/Sub and Graph
change notifications both need an inbound HTTPS endpoint, and Graph cannot even keep a
`/me/messages` subscription alive for a consumer account without a live user token.

**Postgres is the source of truth. Redis is rebuildable. The provider is transport.**

## Things that are true and easy to get wrong

**Google.** One External OAuth client covers personal Gmail *and* Workspace, provided
its consent screen publishing status is **"In production"**. That single setting is
load-bearing: the 7-day refresh-token revocation applies to "Testing" status only, and
an unverified app in production still works with restricted scopes (cost: a one-time
warning screen per mailbox, and a 100-new-user cap that is permanent on the Cloud
project). This is why there is no CASA assessment and no app-password/IMAP path for
Gmail. An "Internal" app would skip the warning but only authorizes Workspace accounts.

**Gmail flags are labels.** A message is unread because it carries `UNREAD`, starred
because it carries `STARRED`. There are no booleans to read.

**Gmail folders are labels too**, so one message is legitimately in several at once.
That is why `message_folders` is a pivot table and not a `messages.folder_id` column,
and why folder links are written with `sync()` — a removed label is reported as a
*shorter list* against an unchanged message id, which `attach()` would never notice.

**Every cursor expires.** Gmail's `historyId` 404s once it ages out; Graph answers
`syncStateNotFound`; IMAP bumps `UIDVALIDITY`. All three surface as
`CursorInvalidException`, and the only recovery is a full resync — which does *not*
delete anything first, because the unique constraint on
`(mail_account_id, provider_message_id)` makes the re-walk an upsert.

**Thread counters are derived, never incremented.** An incremented counter drifts the
first time a job retries and nothing can detect that it has.

**A headers-only sync carries null bodies.** Only write a body when the provider
actually supplied one, or every resync wipes what was fetched on demand.

**Threading has three tiers**: RFC `In-Reply-To`/`References` (the only tier allowed to
merge across mailboxes, because Message-IDs are globally unique), then the provider's
own thread id (same account only), then normalised subject plus participant overlap
inside a time window (same account only — two people mailing "Invoice" to two mailboxes
must not become one thread).

**`APP_KEY` encrypts every stored refresh token.** The Docker entrypoint refuses to
boot without one and never generates one, because a fresh key on boot would leave every
mailbox looking connected and failing silently.

**`app` and `worker` share the `storage/app` volume.** Staged attachment uploads are
written by the web container and read by the worker at send time.

**Only `app` runs migrations** (`RUN_MIGRATIONS=true`). Letting three containers race
on the same migration half-applies a schema.

## Conventions

- **Unimplemented provider methods throw**, they do not return empty data. An empty
  array reads like a working sync, which is the worst failure this design can have.
- **`tests/Support/FakeProvider.php`** is an in-memory `MailboxProvider`, so the whole
  pipeline — backfill, threading, flag push, cursor expiry, full resync — is testable
  with no network and no credentials. Use it rather than mocking Google's client.
- **`DemoSeeder`** builds sample mail *through* `MessageWriter`, so it exercises the
  real threading and folder logic. `php artisan db:seed --class=DemoSeeder`.
- **Drive UI changes in a browser before believing them.** Every UI bug in this
  codebase so far was found that way and not by assertions: a single-line list row that
  truncated every subject, Escape ignored inside the editor, a phone drawer with no way
  to close, an empty `state` on the OAuth URL that a substring assertion had passed.
- `vendor/bin/pint` before committing.
- Say plainly what was verified and what was not. Several things here can only be
  verified against a real mailbox.

## State

`master` has everything through the Docker setup. Branch
`claude/sync-status-diagnostics` has a sync-status fix that is **not merged yet**.

Implemented and tested: schema, sync engine (idempotent persistence, resumable
backfill, cursor-expiry recovery, flag push with revert), HTML sanitizer with
tracking-pixel blocking, split-pane inbox with keyboard navigation, send pipeline
(recipient resolution, RFC 5322 build, quoting, composer), Gmail API adapter, Google
OAuth connect flow, Docker.

**Not verified against anything real:** the Gmail API calls themselves, and the Docker
image has never been built. Both were written in an environment with no Docker daemon
and no way to complete a Google consent screen. Treat first-run failures there as
expected rather than surprising.

Not built: Microsoft Graph adapter, IMAP adapter body, attachment download endpoint,
archive/delete/move (they need `provider->move()`).

See `docs/continue-here.md` for the current debugging task.

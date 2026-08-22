# Unified Email — Architecture

Single-user self-hosted email client. Companion to [`research-and-stack.md`](./research-and-stack.md).

---

## 1. System overview

```
┌───────────────────────┐
│  Vue 3 SPA (Inertia)  │   unified inbox · thread view · composer
└───────────┬───────────┘
            │ Inertia / JSON
┌───────────▼─────────────────────────────────────────┐
│  Laravel 13  (FrankenPHP + Octane, one container)   │
│                                                     │
│  MailboxProvider ──┬── GmailApiProvider   (Workspace)
│                    ├── ImapProvider       (personal @gmail)
│                    └── GraphProvider      (Outlook.com)
└──┬──────────────────────────────┬───────────────────┘
   │                              │
┌──▼────────┐  ┌──────────┐  ┌────▼──────────────────┐
│ Postgres  │  │  Redis   │  │ worker + scheduler    │
│ 16 (FTS)  │  │ (queue)  │  │ imap-idle daemon      │
└───────────┘  └──────────┘  └───────────────────────┘
        │
   ┌────▼──────────┐
   │ local volume  │  attachments
   └───────────────┘

outbound only ──► Gmail API · smtp/imap.gmail.com · graph.microsoft.com
```

সব provider connection **outbound**। কোনো inbound webhook, public endpoint,
tunnel বা static IP লাগে না — laptop-এ, NAS-এ, বা $5 VPS-এ একইভাবে চলবে।

**Postgres = source of truth.** Message body ও persist করব (একজনের mailbox, storage
সমস্যা নয়, আর offline search অনেক ভালো হয়) — SaaS plan-এ যেটা on-demand fetch ছিল।

---

## 2. Provider abstraction

তিনটা provider-এর তিনটা আলাদা protocol, কিন্তু UI-তে একটাই inbox। তাই abstraction
এখানে vendor-independence-এর জন্য নয়, **normalization**-এর জন্য।

```php
interface MailboxProvider
{
    public function connect(MailAccount $a, array $credentials): void;
    public function listFolders(MailAccount $a): array;

    /** Full backfill, cursor-paginated */
    public function fetchPage(MailAccount $a, Folder $f, ?string $cursor): MessagePage;

    /** Incremental: historyId / deltaLink / UID range */
    public function fetchChanges(MailAccount $a, SyncCursor $c): ChangeSet;

    public function getBody(MailAccount $a, string $remoteId): MessageBody;
    public function getAttachment(MailAccount $a, string $remoteId, string $attId): StreamInterface;

    public function send(MailAccount $a, OutboundMessage $m): SendResult;
    public function setFlags(MailAccount $a, array $remoteIds, FlagChange $c): void;
    public function move(MailAccount $a, array $remoteIds, string $folder): void;
}
```

### তিনটা implementation

| | `GmailApiProvider` | `ImapProvider` | `GraphProvider` |
|---|---|---|---|
| Account | Workspace | personal @gmail | Outlook.com |
| Auth | OAuth (Internal app), refresh token | App Password | OAuth `/common`, refresh token |
| Backfill | `messages.list` + `pageToken` | `UID SEARCH` + UID range | `/messages?$top&$skiptoken` |
| Incremental | `history.list(startHistoryId)` | **IDLE** + `UIDNEXT` | `/messages/delta` (`deltaLink`) |
| Cursor invalid | 404 → full resync | `UIDVALIDITY` change → full resync | `syncStateNotFound` → full resync |
| Send | `messages.send` (raw MIME) + `threadId` | SMTP submit | `sendMail` / `createReply` |
| Thread id | native `threadId` | ❌ নেই — header দিয়ে | native `conversationId` |
| Flags | label add/remove (`UNREAD`, `STARRED`) | IMAP flags (`\Seen`, `\Flagged`) | `isRead`, `flag` |
| Folders | labels (message-এ multiple) | folders (message-এ একটা) | mailFolders |

> **Gmail-এর label vs folder mismatch-টা মাথায় রাখতে হবে।** Gmail-এ একটা message
> একসাথে Inbox আর একটা custom label-এ থাকতে পারে; IMAP/Graph-এ folder একটাই।
> তাই DB-তে `message_folders` **many-to-many** রাখছি, `messages.folder_id` নয় —
> নইলে Gmail data ঠিকভাবে বসবে না।

---

## 3. Data model (Postgres)

Multi-tenancy নেই, তাই `tenant_id` সব জায়গা থেকে বাদ।

```sql
users                 id, name, email, password        -- শুধু আমি; auth-এর জন্য

mail_accounts         id, label,                       -- "Work", "Personal", "Outlook"
                      provider,                        -- gmail_api | imap | graph
                      email, display_name,
                      credentials jsonb,               -- ENCRYPTED cast (refresh token / app password)
                      sync_cursor jsonb,               -- {historyId} | {uidnext,uidvalidity} | {deltaLink}
                      status,                          -- connecting|active|auth_error|disabled
                      backfill_done_at, last_synced_at, last_error,
                      signature_html
                      UNIQUE (email, provider)

folders               id, mail_account_id, remote_id, name, path,
                      role,                            -- inbox|sent|drafts|trash|junk|archive|custom
                      is_label,                        -- Gmail label কি না
                      unread_count, total_count

threads               id, subject_normalized, snippet,
                      participants jsonb,
                      first_message_at, last_message_at,
                      message_count, unread_count,
                      has_attachments, is_starred
                      -- একটা thread-এ একাধিক account-এর message থাকতে পারে

messages              id, thread_id, mail_account_id,
                      provider_message_id,             -- Gmail id | IMAP UID | Graph id
                      provider_thread_id,              -- threadId | NULL | conversationId
                      rfc822_message_id,               -- Message-ID header
                      in_reply_to, references_ids text[],
                      from_addr jsonb, to_addrs jsonb, cc_addrs jsonb, bcc_addrs jsonb,
                      reply_to jsonb,
                      subject, snippet,
                      body_html, body_text,            -- persisted
                      search_vector tsvector,          -- GENERATED, GIN index
                      sent_at, received_at,
                      is_read, is_starred, is_draft, is_answered,
                      size_bytes, has_attachments,
                      headers jsonb, raw_hash
                      UNIQUE (mail_account_id, provider_message_id)

message_folders       message_id, folder_id            -- Gmail multi-label-এর জন্য M2M
                      PRIMARY KEY (message_id, folder_id)

attachments           id, message_id, remote_id, filename, mime_type,
                      size_bytes, is_inline, content_id, disk_path

outbound_messages     id, mail_account_id, thread_id, in_reply_to_message_id,
                      type,                            -- new|reply|reply_all|forward
                      to_addrs jsonb, cc_addrs jsonb, bcc_addrs jsonb,
                      subject, body_html, attachments jsonb,
                      status,                          -- draft|queued|sending|sent|failed
                      attempts, error, sent_message_id, sent_at

tags                  id, name, color                  -- আমার নিজের tag (provider label নয়)
message_tag           message_id, tag_id

-- Phase 4: CRM-lite
contacts              id, email UNIQUE, name, company, notes, last_contacted_at
contact_links         id, contact_id, linkable_type, linkable_id   -- thread/message
```

Index-গুলো যেগুলো আসলে লাগবে:
`messages(thread_id)`, `messages(received_at DESC)`, `messages(is_read) WHERE NOT is_read`,
`messages USING GIN(search_vector)`, `messages(rfc822_message_id)`, `threads(last_message_at DESC)`

### Threading

```
1. In-Reply-To / References  →  messages.rfc822_message_id lookup   (cross-account works)
2. provider_thread_id        →  একই account-এর মধ্যে (Gmail threadId / Graph conversationId)
3. normalized subject + participant overlap, ৩০ দিনের window        (last resort)
```

ধাপ ১ globally unique, তাই Workspace-এর একটা reply আর Outlook-এর original message
একই thread-এ merge হতে পারবে — যেটা unified inbox-এর আসল কাজ। ধাপ ৩ দিয়ে
**কখনো cross-account merge করব না**, নইলে অসম্পর্কিত mail এক thread-এ ঢুকবে।

---

## 4. Sync engine

```
scheduler (প্রতি মিনিটে)
   └─► SyncAccountJob(account)          -- withoutOverlapping lock
          │
          ├─ backfill_done_at == null ? ──► BackfillJob (cursor-paginated, chunked)
          │
          └─► provider->fetchChanges(cursor)
                  │
                  ├─ cursor invalid ──► FullResyncJob (cursor reset, backfill আবার)
                  │
                  └─ ChangeSet { new[], updated[], deleted[] }
                          │
                          ├─ upsert messages (idempotent, unique constraint-এর উপর)
                          ├─ resolve thread (৩ ধাপ)
                          ├─ persist body + attachments
                          ├─ sync flags (is_read / is_starred)
                          └─ auto-link contacts

imap-idle daemon (আলাদা long-running process)
   └─► IDLE on INBOX ──► change এলে ──► SyncAccountJob dispatch
          └─ disconnect হলে exponential backoff-এ reconnect
```

নিয়মগুলো:

- **প্রতিটা sync idempotent** — একই run দুইবার চললেও duplicate হবে না
  (`UNIQUE (mail_account_id, provider_message_id)` + upsert)
- **Backfill window** default ৯০ দিন, তারপর background-এ ধীরে ধীরে পুরো history
  (`--since` দিয়ে চালানো যাবে)। Backfill চলার সময়ও inbox ব্যবহারযোগ্য
- **`withoutOverlapping`** ছাড়া চলবে না — নইলে একই account-এ দুইটা sync একসাথে চলবে
- **Rate limit**: Gmail API-তে per-user quota, Graph-এ 10k/10min — একজনের জন্য অনেক,
  তবু `RateLimited` middleware + exponential backoff রাখব (backfill-এ কাজে দেবে)
- **Staleness watchdog**: কোনো account-এর `last_synced_at` ১৫ মিনিটের বেশি পুরোনো হলে
  UI-তে banner + log warning। Silent failure এই design-এর সবচেয়ে বড় শত্রু
- Local flag change (read/star) **optimistic**: DB-তে সাথে সাথে, তারপর
  `PushFlagsJob` provider-এ পাঠায়; fail হলে revert + notify

---

## 5. Send / reply / forward

```
composer ──► outbound_messages (status=queued) ──► SendMessageJob
                                                        │
                              ┌─────────────────────────┼─────────────────────────┐
                        GmailApiProvider           ImapProvider              GraphProvider
                     raw MIME + threadId        SMTP submit (:465)      sendMail / createReply
                              └─────────────────────────┼─────────────────────────┘
                                                        │
                                          status=sent, sent_message_id save
                                          পরের sync-এ Sent folder থেকে message আসবে
```

**Threading header এখন আমাদের নিজের দায়িত্ব** (EmailEngine ছিল না বলে যেটা free পাচ্ছিলাম):

```
In-Reply-To: <parent.rfc822_message_id>
References:  parent.references_ids ++ [parent.rfc822_message_id]     -- ৩২ KB-এর নিচে trim
Subject:     "Re: " ++ parent.subject   (আগে থেকে "Re:" থাকলে যোগ করব না)
```

Provider-ভিত্তিক খুঁটিনাটি:

- **Gmail API**: `Symfony\Component\Mime\Email` দিয়ে MIME build → base64url → `messages.send`
  সাথে `threadId` দিলে Gmail নিজেই thread-এ বসায়। Sent-এ auto-appear করে
- **IMAP/SMTP**: `smtp.gmail.com` দিয়ে পাঠালে Gmail **নিজেই** Sent-এ copy রাখে —
  manual `APPEND` করলে duplicate হবে। (অন্য কোনো IMAP server যোগ করলে সেখানে APPEND লাগবে)
- **Graph**: reply-এ `POST /me/messages/{id}/createReply` → body বসিয়ে `send`;
  এতে Graph নিজেই header ঠিক করে। নতুন mail-এ `POST /me/sendMail`
- Forward: original-এর `body_html` quote করে + attachment re-attach
- Draft আমাদের DB-তে প্রতি ৩ সেকেন্ডে auto-save (provider draft sync করছি না — একজনের
  জন্য অপ্রয়োজনীয় complexity)

---

## 6. Search

Postgres full-text, আলাদা কোনো search service ছাড়া:

```sql
search_vector GENERATED ALWAYS AS (
    setweight(to_tsvector('simple', coalesce(subject,'')), 'A') ||
    setweight(to_tsvector('simple', coalesce(from_addr->>'address','')), 'B') ||
    setweight(to_tsvector('simple', coalesce(body_text,'')), 'C')
) STORED
```

- GIN index; filter: `mail_account_id`, `folder`, `is_read`, `has_attachments`, date range, `has:attachment`
- Query syntax: `from:x@y subject:invoice has:attachment after:2026-01-01`
  → parser দিয়ে filter + tsquery-তে ভাঙা
- একজনের ~১-২ লাখ message-এ Postgres FTS দ্রুত। এর বেশি হলে তখন Meilisearch যোগ করা যাবে

---

## 7. Security

- `mail_accounts.credentials` → Laravel **encrypted cast** (refresh token, app password)
- `APP_KEY` হারালে সব credential অপাঠ্য → **`APP_KEY`-এর backup আলাদা জায়গায় রাখতে হবে**
- Postgres volume host-এ encrypted disk-এ রাখা (laptop হলে FileVault/LUKS যথেষ্ট)
- App-এ login বাধ্যতামূলক (single user, কিন্তু VPS-এ চললে exposed থাকবে) + 2FA
- VPS-এ চালালে app port publicly bind না করা — Tailscale/WireGuard, বা reverse proxy + basic auth
- `docker-compose.yml`-এ কোনো secret নয়, সব `.env`-এ; `.env` git-এ নেই
- Attachment serve করার সময় `Content-Disposition: attachment` + inline HTML sanitize
  (email body render-এ **অবশ্যই** sanitizer, নইলে XSS — `mews/purifier` বা DOMPurify)
- Remote image default-এ block (tracking pixel), "show images" button

---

## 8. Docker Compose

```
services:
  app         FrankenPHP + Laravel (web)
  worker      php artisan queue:work
  scheduler   php artisan schedule:work
  imap-idle   php artisan mail:idle --account=personal-gmail
  postgres    postgres:16
  redis       redis:7-alpine

volumes: pgdata, attachments
```

`app`, `worker`, `scheduler`, `imap-idle` — একই image, ভিন্ন command।
Dev-এ `sail`/compose, prod-এ একই compose file একটা VPS-এ।

---

## 9. Roadmap

| Phase | Scope | Estimate |
|---|---|---|
| **0 — Foundations** ✅ | Laravel 13 + FrankenPHP + compose stack, schema, `MailboxProvider` contract + তিন adapter, thread resolver, reply headers, sync job orchestration, watchdog | **done** |
| **1 — Read one account** | `GraphProvider` আগে (সবচেয়ে সহজ auth) — connect, backfill, folder list, message list, thread view, body render + sanitize, read/unread, star | ১–১.৫ সপ্তাহ |
| **2 — বাকি দুই provider** | `GmailApiProvider` (Internal OAuth + historyId delta), `ImapProvider` (App Password + IDLE daemon), unified inbox, cross-account threading, full-resync fallback, staleness watchdog | ২ সপ্তাহ |
| **3 — Send** | composer (TipTap), send/reply/reply-all/forward তিন provider-এ, header building, attachment upload/download, draft auto-save | ১.৫ সপ্তাহ |
| **4 — Daily-driver polish** | Postgres FTS + query parser, tags, bulk action, keyboard shortcut, archive/trash/move, signature, remote-image blocking | ১.৫ সপ্তাহ |
| **5 — CRM-lite** (optional) | contacts, auto-link, contact timeline, notes | ১ সপ্তাহ |

**Phase 1-এ Outlook দিয়ে শুরু** — Graph-এর auth সবচেয়ে সহজ, তাই end-to-end pipeline
দ্রুত দাঁড়াবে; তারপর বাকি দুইটা adapter সেই pipeline-এ বসবে।

মোট ~৭-৮ সপ্তাহ part-time।

### Phase 0-এ কী দাঁড়িয়েছে

সব verified — ৩৭টা test pass, migration আসল Postgres 16-এ চলেছে।

| জিনিস | অবস্থা |
|---|---|
| Laravel 13.26 + FrankenPHP Dockerfile + ৬-service compose stack | ✅ |
| পুরো schema (৮টা migration), tsvector generated column + GIN index সহ | ✅ চলে |
| `MailboxProvider` contract + ১২টা normalized DTO | ✅ |
| `ThreadResolver` — তিন tier, cross-account merge সহ | ✅ tested |
| `ReplyHeaders` — `Re:`/`Fwd:` prefix, References chain trim | ✅ tested |
| `SyncAccountJob` — overlap lock, cursor handling, `CursorInvalidException` → full resync | ✅ |
| `FullResyncJob` — cursor reset, message delete না করে upsert-এ ভরসা | ✅ |
| `mail:sync`, `mail:watchdog`, scheduler wiring | ✅ চলে |
| Credentials encryption at rest | ✅ tested |
| তিন adapter-এর client bootstrap (Gmail/Graph/IMAP) | ✅ wired |
| তিন adapter-এর আসল protocol call | ⏳ Phase 1-2 |
| `BackfillJob`, `SendMessageJob`, `PushFlagsJob`, `mail:idle` body | ⏳ Phase 1-3 |
| UI (Inertia/Vue) | ⏳ Phase 1 |

যেসব method এখনো implement হয়নি সেগুলো নীরবে খালি data ফেরত দেয় না — স্পষ্ট
exception ছোড়ে ("not implemented yet, roadmap Phase N")। খালি array ফেরত দিলে সেটা
কাজ করা sync-এর মতো দেখাবে, যেটা এই design-এ সবচেয়ে বিপজ্জনক failure।

---

## 10. Decisions locked in

1. **EmailEngine বাদ** — ৩টা account-এ $1,450/yr + extra service অযুক্তিযুক্ত; direct API
2. Workspace Gmail → **Internal OAuth app** (verification নেই, token expire করে না)
3. Personal Gmail → **App Password + IMAP/SMTP** (CASA এড়ানোর একমাত্র বাস্তব পথ)
4. Outlook.com → **Graph**, `/common` app registration, publisher verification ছাড়াই
5. **Polling + IMAP IDLE, webhook নয়** — কোনো public endpoint/tunnel লাগবে না
6. Postgres = source of truth, **body সহ** persisted; search-ও Postgres-এ (`tsvector`)
7. `message_folders` **M2M** — Gmail-এর label model ধরার জন্য
8. Threading: RFC header > provider thread id > subject heuristic; cross-account merge শুধু header দিয়ে
9. Reply header (`In-Reply-To`/`References`) **আমরা নিজে build করব** — Graph-এ `createReply` ব্যবহার করে ওটা provider-কে দেওয়া হবে
10. Multi-tenancy, billing, S3, Meilisearch, Reverb — সব বাদ; দরকার হলে পরে

# Unified Email SaaS — Architecture

Companion to [`research-and-stack.md`](./research-and-stack.md).

---

## 1. System overview

```
┌──────────────┐   Inertia/JSON    ┌───────────────────────────────┐
│  Vue 3 SPA   │◄─────────────────►│   Laravel 13 (app + API)      │
│  (inbox UI)  │   WS (Reverb)     │                               │
└──────────────┘                   │  Horizon workers:             │
                                   │   sync · send · index · hook  │
                                   └───┬───────────┬───────────┬───┘
                                       │           │           │
                            REST       │      ┌────▼────┐  ┌───▼────────┐
                    ┌──────────────────▼──┐   │Postgres │  │Meilisearch │
                    │    EmailEngine      │   └─────────┘  └────────────┘
                    │  (Node + redis-ee)  │        │
                    └──┬───────────────┬──┘   ┌────▼────┐
          Gmail API /  │               │      │ S3 / R2 │ (attachments)
          Pub/Sub push │               │ MS   └─────────┘
                  ┌────▼────┐     ┌────▼──────┐
                  │  Gmail  │     │ MS Graph  │
                  └─────────┘     └───────────┘
                        │ webhooks (messageNew, ...)
                        └──────────► POST /webhooks/emailengine
```

**Golden rule:** EmailEngine হলো *transport*. Application state-এর source of truth
আমাদের Postgres. EmailEngine উড়ে গেলে re-sync করা যাবে; আমাদের DB উড়ে গেলে সব শেষ।

Message **body** default-এ EmailEngine দিয়ে on-demand fetch হবে (+ short Redis cache)।
আমাদের DB-তে যাবে metadata + snippet + search text। এতে storage কম, compliance সহজ।

---

## 2. Provider abstraction

```php
interface MailboxProvider
{
    public function createAccount(MailAccount $a, OAuthGrant $g): string;   // returns remote id
    public function listMessages(MailAccount $a, string $folder, ?string $cursor): MessagePage;
    public function getMessage(MailAccount $a, string $remoteId): MessageDetail;
    public function getAttachment(MailAccount $a, string $attachmentId): StreamInterface;
    public function send(MailAccount $a, OutboundMessage $m): SendResult;    // new / reply / forward
    public function setFlags(MailAccount $a, array $remoteIds, FlagChange $c): void;
    public function search(MailAccount $a, SearchQuery $q): MessagePage;
    public function deleteAccount(MailAccount $a): void;
}
```

`EmailEngineProvider` হবে একমাত্র implementation (v1)। Application code কখনো সরাসরি
EmailEngine HTTP client ছোঁবে না — সব এই interface দিয়ে। এতে পরে Nylas/direct-API-তে
সরানো একটা adapter লেখার কাজ, rewrite নয়।

---

## 3. Data model (Postgres)

```
tenants                 id, name, plan, settings jsonb
users                   id, tenant_id, name, email, role
mail_accounts           id, tenant_id, user_id, provider(gmail|outlook|imap),
                        email, display_name, ee_account_id,
                        status(connecting|active|auth_error|disabled),
                        sync_state jsonb, last_synced_at,
                        oauth_expires_at
                        UNIQUE (tenant_id, email)

mailboxes               id, mail_account_id, remote_path, name,
                        specialUse(\Inbox|\Sent|\Drafts|\Trash|\Junk),
                        total_count, unread_count

threads                 id, tenant_id, subject, snippet,
                        participants jsonb, last_message_at,
                        message_count, has_attachments,
                        unread_count, is_starred
                        -- cross-account: এক thread-এ Gmail+Outlook message থাকতে পারে

messages                id, tenant_id, thread_id, mail_account_id, mailbox_id,
                        provider_message_id,      -- EmailEngine message id
                        rfc822_message_id,        -- RFC5322 Message-ID header
                        in_reply_to, references text[],
                        from jsonb, to jsonb, cc jsonb, bcc jsonb, reply_to jsonb,
                        subject, snippet,
                        sent_at, received_at,
                        is_read, is_starred, is_draft, is_answered,
                        size_bytes, has_attachments,
                        raw_headers jsonb
                        UNIQUE (mail_account_id, provider_message_id)
                        UNIQUE (tenant_id, rfc822_message_id)   -- dedupe cross-account

attachments             id, message_id, remote_id, filename, mime_type,
                        size_bytes, is_inline, content_id,
                        cached_disk_path NULL, cached_at NULL

outbound_messages       id, tenant_id, mail_account_id, thread_id NULL,
                        type(new|reply|reply_all|forward),
                        payload jsonb, status(queued|sent|failed|bounced),
                        provider_response jsonb, queued_id,
                        attempts, error, sent_at

labels                  id, tenant_id, name, color        -- আমাদের নিজের label
label_message           label_id, message_id

webhook_events          id, ee_event_id UNIQUE, account, event_type,
                        payload jsonb, processed_at, error   -- idempotency ledger

-- CRM bridge (Phase 4)
crm_contacts            id, tenant_id, email, name, company, external_ref
message_links           id, message_id, linkable_type, linkable_id  -- contact/deal/ticket
thread_links            id, thread_id, linkable_type, linkable_id
```

### Threading strategy

তিন ধাপে thread resolve করা হয়, ক্রমানুসারে:

1. **RFC headers** — `In-Reply-To` / `References` → বিদ্যমান `messages.rfc822_message_id` match
2. **Provider thread id** — Gmail `threadId`, Graph `conversationId` (একই account-এর মধ্যে reliable)
3. **Fallback heuristic** — normalized subject (`Re:`/`Fwd:`/`RE:` strip) + participant set overlap
   + ৩০ দিনের window

Cross-provider thread merge শুধু ধাপ ১ দিয়ে করা হবে (headers globally unique) — ধাপ ৩ দিয়ে
কখনো নয়, নইলে ভুল thread merge হবে।

---

## 4. Sync flow

### Initial connect
1. User "Connect Gmail/Outlook" চাপে → EmailEngine-এর hosted auth URL (বা আমাদের নিজের OAuth flow) → consent
2. Callback → `POST /v1/account` EmailEngine-এ (refresh token সহ) → `ee_account_id` save
3. `InitialSyncJob` dispatch → mailbox list → per-mailbox paginated backfill (newest first),
   ছোট ছোট chunk-এ (`page` cursor `sync_state`-এ persist)
4. Backfill চলার সময়ই inbox দেখানো শুরু (progressive), progress bar দিয়ে

Backfill window default **90 দিন**, plan অনুযায়ী configurable (পুরো history টানলে খরচ ও rate limit বাড়ে)।

### Ongoing (real-time)
```
Gmail change ──► Pub/Sub ──► EmailEngine ──► webhook ──► POST /webhooks/emailengine
Outlook change ──► Graph subscription ──┘                     │
                                                              ▼
                                            verify HMAC → insert webhook_events
                                            (ON CONFLICT DO NOTHING) → 200 OK fast
                                                              │
                                                    ProcessWebhookJob (queue: hook)
                                                              │
                                          upsert message → resolve thread → index →
                                          broadcast to Reverb → CRM auto-link
```

**Webhook endpoint কখনো heavy কাজ করবে না** — শুধু verify + persist + 200 return করে
(EmailEngine timeout হলে retry করবে, তাতে duplicate হবে — তাই `ee_event_id` unique)।

### Safety net
- প্রতি ১৫ মিনিটে `ReconcileAccountJob` — সব active account-এর জন্য
  EmailEngine-এর message count vs আমাদের count, mismatch হলে delta re-fetch
- Gmail Pub/Sub `watch` expiry (7 দিন) — EmailEngine renew করে, কিন্তু আমরাও
  `last_webhook_at` monitor করব; ২ ঘণ্টা চুপ থাকলে alert

---

## 5. Send / reply / forward

```
User composes ──► validate ──► outbound_messages row (status=queued)
                                        │
                                 SendMessageJob (queue: send)
                                        │
                              POST /v1/account/{id}/submit
                              (reference: {message: <id>, action: reply|forward})
                                        │
                     EmailEngine → provider SMTP/API → Sent folder-এ appear
                                        │
                          queuedId save; delivery/bounce webhook-এ status update
```

- **Reply/forward-এ header correctness EmailEngine-এর `reference` দিয়েই করা হবে** —
  নিজে `In-Reply-To`/`References` বানানোর চেষ্টা করব না, ওটা bug-এর খনি
- Optimistic UI: compose-এর সাথে সাথেই thread-এ message দেখাবে (`status=queued`), fail হলে retry banner
- Draft auto-save প্রতি ৩ সেকেন্ডে আমাদের DB-তে (provider draft sync Phase 3)
- Attachment: browser → presigned S3 upload → send-এর সময় EmailEngine-এ stream

---

## 6. Search

- Meilisearch index: `messages` — একটাই index, প্রতিটা document-এ `tenant_id`
- Tenant isolation **Meilisearch tenant token** দিয়ে (`tenant_id = X` filter, backend-এ generate)
- Indexed: subject, from/to/cc names+addresses, body text (HTML stripped), attachment filenames
- Filterable: `tenant_id`, `mail_account_id`, `is_read`, `has_attachments`, `sent_at`, `label_ids`
- Index job আলাদা queue-তে (`index`), sync-এর critical path block করে না
- Fallback: Meilisearch down হলে Postgres `tsvector` দিয়ে degraded search

---

## 7. CRM integration (Phase 4)

- Incoming message-এ প্রতিটা participant email → `crm_contacts` lookup → auto `thread_link`
- Contact page-এ পুরো email timeline (দুই provider মিলিয়ে)
- Manual "attach to deal/lead" action
- Outbound event bus (`MessageReceived`, `MessageSent`) → external CRM webhook
- CRM যদি আলাদা service হয়: `message_links` polymorphic রাখা হয়েছে যাতে দুটোই কাজ করে

---

## 8. Security & compliance

- OAuth token EmailEngine-এই থাকে (encrypted, license key দিয়ে); আমরা token DB-তে রাখব না
- আমাদের DB-তে যা রাখি: `pgcrypto`/Laravel encrypted casts দিয়ে snippet ও header encrypt
- Attachment cache: server-side encryption + TTL (default ৭ দিন) auto-purge
- Tenant isolation: global Eloquent scope + PG row-level security (defense in depth)
- Webhook: HMAC signature verify + IP allowlist + replay window
- Audit log: কে কোন message দেখল/পাঠাল
- Google **Limited Use** policy: Gmail data দিয়ে model train করা যাবে না, human read করা যাবে না
  (security/abuse ছাড়া) — privacy policy-তে স্পষ্ট লিখতে হবে
- Account disconnect → EmailEngine account delete + আমাদের data purge (৩০ দিনের grace)

---

## 9. Roadmap

| Phase | Scope | Estimate |
|---|---|---|
| **0 — Foundations** | Laravel 13 skeleton, tenants/users/auth, Docker Compose (app+PG+2 Redis+Meili+EmailEngine), CI. **সাথেই Google + Microsoft OAuth app registration ও verification submit** | ১–২ সপ্তাহ |
| **1 — Connect + read** | `MailboxProvider` + EmailEngineProvider, OAuth connect flow (Outlook আগে), initial sync, unified inbox list, thread view, read/unread, star | ৩–৪ সপ্তাহ |
| **2 — Real-time + send** | Webhook pipeline + idempotency, Reverb live updates, composer (send/reply/reply-all/forward), attachment up/download, drafts | ৩–৪ সপ্তাহ |
| **3 — Productionize** | Meilisearch search, labels, bulk actions, reconcile job, monitoring/alerting, rate limiting, error recovery UI, billing | ৩–৪ সপ্তাহ |
| **4 — CRM bridge** | contacts, auto-link, contact timeline, manual attach, outbound webhooks | ২–৩ সপ্তাহ |

Gmail launch phase 3-এর পরে, CASA clear হওয়ার উপর নির্ভর করে (parallel track)।

---

## 10. Decisions locked in

1. EmailEngine self-hosted — flat license, data আমাদের
2. সব provider call `MailboxProvider` interface দিয়ে — vendor lock-in নেই
3. Postgres = source of truth; EmailEngine = transport; Redis = rebuildable state
4. Message body on-demand, metadata persisted
5. Threading: RFC headers > provider thread id > subject heuristic
6. Webhook endpoint = thin; সব কাজ queue-তে; `ee_event_id` unique দিয়ে idempotent
7. Outlook আগে ship, Gmail verification parallel-এ

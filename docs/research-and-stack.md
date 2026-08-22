# Unified Email SaaS — Feasibility, Tech Stack & Build-vs-Buy Research

Status: research / decision document
Date: 2026-08-22

---

## 1. Short answer: হ্যাঁ, বানানো সম্ভব

Front / Missive / HubSpot Conversations-style একটা unified inbox বানানো technically
solved problem. আসল কাজটা email protocol নয় — আসল কাজ তিনটা:

1. **Provider compliance** (Google CASA + Microsoft publisher verification) — সবচেয়ে বড় blocker, code নয়।
2. **Sync correctness** (delta sync, idempotency, threading, dedupe) — এখানেই বেশিরভাগ project ভাঙে।
3. **Cost per connected mailbox** — এটাই ঠিক করে দেয় build vs buy.

EmailEngine এই তিনটার মধ্যে ২ নম্বরটা প্রায় পুরোটা solve করে দেয়, ১ নম্বরটা করে না
(ওটা আমাদের নিজের OAuth app-এর দায়), আর ৩ নম্বরে সবচেয়ে ভালো deal দেয়।

---

## 2. Email layer: build vs buy

| Option | Model | Pricing (2026) | Data ownership | Verdict |
|---|---|---|---|---|
| **EmailEngine** (Postal Systems) | Self-hosted email API, Node + Redis | **~$1,450 / €1,200 per year flat**, unlimited mailboxes + unlimited instances | 100% আমাদের infra-তে | ✅ **Recommended** |
| Nylas | Managed unified API | ~$15/mo incl. 5 accounts, then ~$2/account/mo | Nylas-এর cloud | Compliance-heavy enterprise হলে |
| Aurinko | Managed unified API | ~$1/account/mo | Aurinko cloud | সস্তা, কিন্তু vendor lock-in |
| Unipile | Managed, email + LinkedIn/WhatsApp | €49/mo (10 accounts), then ~€5/account/mo | Unipile cloud | LinkedIn/WhatsApp দরকার হলে |
| Raw Gmail API + Graph API নিজে লেখা | DIY | $0 license | আমাদের | ❌ 3-6 months extra dev, প্রতিটা edge case নিজে খেতে হবে |

**Break-even:** per-account pricing-এ ~20-40টা mailbox-এর পরেই EmailEngine সস্তা হয়ে যায়।
SaaS হিসেবে যদি ১০০+ customer target, তাহলে flat license ছাড়া unit economics কাজ করবে না।

**তাই decision: EmailEngine, self-hosted.**

কিন্তু একটা abstraction layer অবশ্যই রাখব (`MailboxProvider` interface) — যাতে কখনো
EmailEngine থেকে সরতে হলে পুরো app rewrite করতে না হয়।

### EmailEngine যা দেয়

- IMAP/SMTP + **native Gmail API** (Pub/Sub push সহ) + **native Microsoft Graph**
- OAuth2 app management (Google + Microsoft), token refresh নিজে handle করে
- Webhooks: `messageNew`, `messageDeleted`, `messageUpdated` (flag change), `mailboxNew`, ইত্যাদি
- REST: list/get/search messages, send (threading + Sent folder সহ), attachment download, flag update
- Delta sync, retry queue, bounce detection, suppression list
- Redis-কে database হিসেবে ব্যবহার করে (`noeviction` policy), **message body store করে না** — on demand fetch করে

### যা দেয় না (আমাদের করতে হবে)

- Multi-tenant user/team model, permissions
- নিজের DB-তে message metadata persist (search + CRM linking-এর জন্য দরকার)
- UI (inbox, thread view, composer)
- Full-text search index
- CRM entity matching

---

## 3. Compliance — এটাই timeline-এর আসল risk

### Google (Gmail)

- EmailEngine-এর Gmail API mode-এ দরকার `https://www.googleapis.com/auth/gmail.modify`
  (+ Pub/Sub-এর জন্য একটা service account with `https://www.googleapis.com/auth/pubsub`)
- `gmail.modify` একটা **restricted scope** → **CASA Tier 2 security assessment বাধ্যতামূলক**
- 2026-এ self-serve CASA Tier 2 lab fee সাধারণত **$540 – $1,000** (legacy manual track অনেক বেশি ছিল)
- Google-এর নিজের brand + scope review সহ total timeline **4–12+ সপ্তাহ**
- **প্রতি বছর re-certification** লাগে; miss করলে production access revoke হতে পারে
- লাগবে: public homepage, privacy policy (Limited Use disclosure সহ), demo video যেটা পুরো consent flow দেখায়

> **এর মানে:** Google verification প্রথম দিনেই শুরু করতে হবে, dev-এর শেষে নয়।
> Verification pending থাকা অবস্থায় app "Testing" mode-এ ১০০ জন test user পর্যন্ত চলবে —
> pilot customer দিয়ে শুরু করার জন্য সেটাই যথেষ্ট।

### Microsoft (Outlook / M365)

- Scopes: `Mail.ReadWrite`, `Mail.Send`, `offline_access`, `User.Read` (delegated)
- Multi-tenant app + **publisher verification** (Microsoft AI Cloud Partner Program ID লাগে, 1–5 business days)
- Verification ছাড়া consent screen-এ "unverified publisher" warning দেখাবে — enterprise customer আটকে যাবে
- Enterprise tenant-এ প্রায়ই **admin consent** লাগবে
- ⚠️ **31 Dec 2026 থেকে**: delivered message-এর subject/body/recipients modify করতে হলে
  elevated `Mail-Advanced.ReadWrite` লাগবে। আমাদের use case (read/send/reply/flag) এতে পড়ে না,
  কিন্তু draft-editing feature বানালে খেয়াল রাখতে হবে।

Google-এর তুলনায় Microsoft অনেক সহজ ও সস্তা → **Outlook দিয়ে ship করো আগে**, Gmail parallel-এ verify হোক।

---

## 4. Recommended tech stack

### Backend
- **PHP 8.3+ / Laravel 13** (latest major, March 2026) — API + jobs + web
- **PostgreSQL 16+** — messages/threads metadata, tenants, CRM links (JSONB + full-text fallback)
- **Redis 7** — দুইটা আলাদা instance:
  - `redis-app`: Laravel cache/session/queue
  - `redis-ee`: EmailEngine-এর নিজের store (`maxmemory-policy noeviction`, persistent, backup সহ)
- **Laravel Horizon** — queue supervision; operation-ভিত্তিক আলাদা queue (`sync`, `send`, `index`, `webhook`)
- **Laravel Reverb** (বা Soketi) — inbox-এ real-time new-mail push
- **Meilisearch** — email full-text search (Laravel Scout driver; PG FTS দিয়ে শুরু করে পরে migrate করা যায়)
- **S3-compatible object storage** (Cloudflare R2 / MinIO) — attachment cache
- **EmailEngine** — email protocol layer

### Frontend
- **Inertia.js + Vue 3 + TypeScript + Tailwind** — SPA-feel, কিন্তু Laravel-এর auth/routing রাখা যায়
  (React পছন্দ হলে Inertia + React, একই architecture)
- **TipTap** — rich-text composer (HTML email body)
- অথবা internal/admin tooling দ্রুত লাগলে **Filament 4** panel

### Infra
- Docker Compose (dev) → single VPS/Hetzner বা AWS ECS (prod)
- EmailEngine একটা আলাদা service container, শুধু internal network-এ exposed
- Webhook endpoint HMAC-signed, IP-allowlisted

### কেন এই choice
- Laravel queue + Horizon জিনিসটা exactly এই workload-এর জন্য: বহু ছোট ছোট idempotent sync job
- Postgres JSONB → provider-এর raw payload রাখা যায় schema না ভেঙে
- Meilisearch সস্তা ও fast; Elasticsearch overkill যতক্ষণ না millions of messages
- Reverb Laravel-native, আলাদা Node service maintain করতে হয় না

---

## 5. খরচ (আনুমানিক, বছর ১)

| Item | Cost |
|---|---|
| EmailEngine license | ~$1,450 / yr |
| Google CASA Tier 2 (annual) | ~$540 – $1,000 / yr |
| Microsoft Partner (MPN/PGA) | সাধারণত $0 – nominal |
| Hosting (app + PG + 2×Redis + Meilisearch) | ~$60 – $200 / mo |
| Object storage (attachments) | ~$5 – $30 / mo |
| **Total year 1 (infra + license)** | **~$3,000 – $5,000** |

Per-mailbox marginal cost প্রায় শূন্য → SaaS pricing-এ ভালো margin.

---

## 6. প্রধান risk

| Risk | Mitigation |
|---|---|
| Google verification আটকে যাওয়া / দেরি | Day 1-এ শুরু; Outlook দিয়ে launch; Gmail-এ IMAP+OAuth fallback path রাখা |
| Redis data loss = sync state হারানো | AOF persistence + daily snapshot; state আমাদের PG-তেও mirror করা |
| Webhook duplicate/out-of-order | `provider_message_id` unique constraint + idempotent upsert |
| Provider rate limit | per-account throttled queue, exponential backoff |
| EmailEngine vendor risk (single-vendor) | `MailboxProvider` interface abstraction; perpetual license option আছে |
| Compliance: inbox content রাখা | metadata + preview default, full body opt-in/TTL; encryption at rest |

---

## Sources

- [EmailEngine](https://emailengine.app/) · [docs](https://learn.emailengine.app/docs) · [licensing](https://learn.emailengine.app/docs/licensing) · [Gmail API scopes](https://learn.emailengine.app/docs/accounts/gmail/gmail-api-scopes) · [Gmail Pub/Sub](https://learn.emailengine.app/docs/accounts/gmail/gmail-pubsub)
- [Google — Restricted scope verification](https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification)
- [Google CASA tiers & costs](https://deepstrike.io/blog/google-casa-security-assessment-2025)
- [Microsoft Graph permissions reference](https://learn.microsoft.com/en-us/graph/permissions-reference) · [permissions overview](https://learn.microsoft.com/en-us/graph/permissions-overview)
- [Mail-Advanced.ReadWrite change (Dec 2026)](https://4sysops.com/archives/mail-advancedreadwrite-permissions-required-to-change-sensitive-email-properties-in-exchange-online-via-graph-api/)
- [Nylas vs Unipile vs Aurinko vs EmailEngine comparison](https://stackreferee.com/email-api-infrastructure/nylas-vs-unipile-vs-aurinko-vs-nango-vs-emailengine) · [Unipile email API providers](https://www.unipile.com/email-api-providers/)
- [Laravel 13 release notes](https://laravel.com/docs/13.x/releases)

# Unified Email — Scope, Stack & Auth Research

Status: decided
Date: 2026-08-22
Scope: **single-user, self-hosted personal tool** (SaaS নয়)

---

## 1. Scope

শুধু আমার নিজের ব্যবহারের জন্য একটা unified email client। Local Docker, বা একটা
ছোট VPS/cloud box-এ চলবে। কোনো customer নেই, কোনো signup নেই, কোনো billing নেই।

তিনটা account connect হবে:

| # | Account | Provider path |
|---|---|---|
| 1 | Google Workspace (নিজের domain) | **Gmail API** (Internal OAuth app) |
| 2 | Personal `@gmail.com` | **IMAP/SMTP + App Password** |
| 3 | Personal Outlook.com | **Microsoft Graph** (`/common` app registration) |

এই scope-এ আগের SaaS plan-এর দুইটা বড় খরচ সম্পূর্ণ বাদ গেল: **EmailEngine license
($1,450/yr)** আর **Google CASA assessment ($540+/yr, recurring)**। মোট license খরচ **$0**।

---

## 2. Email layer: EmailEngine বাদ, direct API

EmailEngine-এর license **১৪ দিনের trial**, তারপর $1,450/yr — আর ওর মূল selling point
("unlimited mailboxes, flat price") এখানে zero value, কারণ mailbox মাত্র ৩টা।
৩টা account-এর জন্য একটা extra Node service + Redis instance + বার্ষিক license
maintain করা pure overhead।

**Decision: Laravel-এ সরাসরি provider API।**

| | Direct API (chosen) | EmailEngine |
|---|---|---|
| License | $0 | $1,450/yr trial-এর পরে |
| Extra services | নেই | Node + আলাদা Redis |
| Dev effort | ~২ সপ্তাহ বেশি | কম |
| Control | সম্পূর্ণ | vendor-এর abstraction-এর মধ্যে |
| ৩টা account-এ যুক্তিযুক্ত? | ✅ | ❌ |

PHP packages:
- `google/apiclient` + `google/apiclient-services` — Gmail API
- `microsoft/microsoft-graph` (official PHP SDK, v2) — Graph
- `webklex/php-imap` — IMAP/SMTP, **IDLE ও OAuth support করে**, PHP `imap` extension লাগে না
- `symfony/mime` — RFC822 message build/parse (Laravel-এর ভিতরেই আছে)

তবু `MailboxProvider` interface-টা রাখছি — কারণ এখন তিনটা আলাদা implementation লাগবে,
abstraction-টা vendor-independence-এর জন্য নয়, **তিন provider-কে এক UI-তে আনার জন্য**।

---

## 3. Auth — প্রতিটা account-এর আলাদা পথ, আলাদা কারণে

### 3.1 Google Workspace → Gmail API, "Internal" OAuth app ⭐

নিজের domain Workspace-এ থাকায় OAuth consent screen **"Internal"** করা যায়। এতে:

- ❌ verification লাগে **না** (CASA-ও না) — restricted scope নিয়েও
- ❌ ৭ দিনের refresh-token expiry **নেই** (ওটা শুধু External + Testing-এর নিয়ম)
- ❌ ১০০ test-user cap নেই
- ✅ Gmail API-র সব কিছু, Pub/Sub push সহ

Scopes: `gmail.modify` (read + flag + label), `gmail.send`, `gmail.labels`.

> এটাই সবচেয়ে পরিষ্কার পথ। Setup: Google Cloud project → OAuth consent screen
> = Internal → Desktop/Web client → refresh token একবার নিয়ে DB-তে encrypted রাখা।

### 3.2 Personal @gmail.com → App Password + IMAP/SMTP

Personal Gmail Workspace org-এর বাইরে, তাই Internal app ওটাকে authorize করতে পারবে না।
বাকি দুইটা OAuth পথ দুইভাবেই খারাপ:

- External + **Testing** mode → refresh token **৭ দিনে revoke** → প্রতি সপ্তাহে re-auth
- External + **Production** + restricted scope → **CASA Tier 2** লাগবে, ~$540+/yr recurring

**তাই App Password।** ২০২৬-এও কাজ করে — March 2025-এ শুধু *regular password* বন্ধ
হয়েছে, 2FA-এর পরে generate করা 16-character app password ঠিকই চলে। কোনো OAuth app,
verification, বা token expiry নেই।

- Server: `imap.gmail.com:993` (SSL), `smtp.gmail.com:465` (SSL)
- ⚠️ 2-Step Verification অবশ্যই on থাকতে হবে
- ⚠️ Google account-এর main password বদলালে app password revoke হয়ে যায় → re-enter করতে হবে
- Real-time: **IMAP IDLE** — outbound connection, কোনো public endpoint লাগে না, প্রায় instant

### 3.3 Personal Outlook.com → Microsoft Graph

- Basic auth ২০২২-এই মরেছে, তাই OAuth বাধ্যতামূলক — কিন্তু এটা সহজ
- একটা **free personal Microsoft account** দিয়েই Entra app registration করা যায়
- Supported account types: *"Accounts in any organizational directory and personal
  Microsoft accounts"*, authority `/common` (বা শুধু personal হলে `/consumers`)
- **Publisher verification লাগবে না** — নিজে একমাত্র user, শুধু প্রথমবার
  "unverified publisher" screen-টা নিজে accept করে নেব
- Graph API **free**; throttle 10,000 requests / 10 min per app per mailbox — একজনের জন্য বিপুল
- Scopes (delegated): `Mail.ReadWrite`, `Mail.Send`, `offline_access`, `User.Read`

⚠️ **Dec 31, 2026 থেকে** delivered message-এর subject/body/recipients modify করতে elevated
`Mail-Advanced.ReadWrite` লাগবে। আমাদের read/send/reply/flag এতে পড়ে না — কিন্তু
provider-side draft editing বানালে খেয়াল রাখতে হবে।

---

## 4. Real-time: polling + IDLE, webhook নয় ⭐

Gmail Pub/Sub push আর Graph change notification — দুইটারই **public HTTPS endpoint**
দরকার। Local Docker বা home server-এ চালাতে হলে Cloudflare Tunnel/ngrok maintain
করতে হবে। আর একটা বড় বাধা: Graph-এ personal account-এর `/me/messages` subscription
user token ছাড়া maintain করা **যায় না**, আর subscription-এর expiry অল্প (renew করতে হয়)।

একজন user, ৩টা mailbox — polling-ই যথেষ্ট এবং অনেক কম moving parts:

| Account | Mechanism | Latency |
|---|---|---|
| Workspace Gmail | `users.history.list` (stored `historyId`), প্রতি ৩০-৬০ সে. | ~৩০-৬০ সে. |
| Personal Gmail | **IMAP IDLE** (long-lived outbound connection) | প্রায় instant |
| Outlook.com | Graph `/messages/delta` (stored `deltaLink`), প্রতি ৩০-৬০ সে. | ~৩০-৬০ সে. |

**কোনো public endpoint, tunnel, বা static IP লাগবে না।** পরে চাইলে Workspace account-এ
Pub/Sub push যোগ করা যাবে — sync engine-এর ভিতরে সেটা শুধু আরেকটা trigger, নতুন pipeline নয়।

Delta token চিরস্থায়ী নয় — Gmail `historyId` পুরোনো হলে 404 দেয়, Graph
`syncStateNotFound` দেয়, IMAP-এ `UIDVALIDITY` বদলে যায়। তিনটা ক্ষেত্রেই fallback একটাই:
**full re-sync**। এটা ধরে না রাখলে account চুপচাপ stale হয়ে যাবে — এটাই এই design-এর
সবচেয়ে সহজে ভুল হওয়া জায়গা।

---

## 5. Stack

| Layer | Choice | কেন |
|---|---|---|
| Backend | PHP 8.3+ / **Laravel 13** | latest major (Mar 2026) |
| Runtime | **FrankenPHP** (Octane) | একটা container-এ web server + PHP |
| DB | **PostgreSQL 16** | JSONB (raw payload) + `tsvector` FTS → আলাদা search engine লাগে না |
| Queue | **Redis** + `queue:work` | Horizon optional; ৩টা account-এ overkill |
| Search | **Postgres full-text** (`tsvector`) | Meilisearch বাদ — একজনের mailbox-এ প্রয়োজন নেই |
| Frontend | **Inertia + Vue 3 + TS + Tailwind** | SPA feel, Laravel auth/routing রাখা যায় |
| Composer UI | **TipTap** | HTML email body |
| Attachments | **local volume** | S3 বাদ |
| Live UI | polling (১৫ সে.) বা Reverb | polling দিয়ে শুরু, দরকার হলে Reverb |

### SaaS plan থেকে যা বাদ গেল

EmailEngine · আলাদা Redis instance · Meilisearch · S3/R2 · Reverb (v1-এ) ·
multi-tenancy · row-level security · billing · CASA verification · webhook endpoint + HMAC ·
tenant-scoped queues

Container সংখ্যা ৬-৭ থেকে **৪**-এ নামল: `app`, `postgres`, `redis`, `worker`
(+ IMAP IDLE-এর জন্য একটা long-running process)।

---

## 6. খরচ

| Item | Cost |
|---|---|
| সব license (Gmail API, Graph, Laravel, Postgres) | **$0** |
| Google CASA | **$0** (Internal app + App Password, তাই লাগে না) |
| Microsoft publisher verification | **$0** (লাগে না) |
| Local Docker-এ চালালে hosting | **$0** |
| ছোট VPS-এ চালালে (2GB RAM যথেষ্ট) | ~$5–10 / mo |

---

## 7. Risk

| Risk | Mitigation |
|---|---|
| Delta/history token expire → silent stale | প্রতি provider-এ full-resync fallback + `last_synced_at` staleness alert |
| App password revoke (Google password বদলালে) | `auth_error` status + UI-তে re-enter prompt |
| IMAP IDLE connection মরে যাওয়া | supervised daemon, auto-reconnect, backoff, সাথে safety-net polling |
| Token আমাদের DB-তে (EmailEngine আর রাখছে না) | Laravel encrypted casts, `.env` `APP_KEY` backup আলাদা রাখা, DB volume encrypted |
| Duplicate message (retry/re-sync) | `(mail_account_id, provider_message_id)` unique + idempotent upsert |
| একা maintainer | provider quirk-গুলো doc-এ লিখে রাখা; magic কম, boring code বেশি |

---

## Sources

- [Google — restricted scope verification](https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification) · [manage app audience / Internal vs External](https://support.google.com/cloud/answer/15549945?hl=en)
- [Google OAuth refresh token 7-day rule](https://www.unipile.com/google-oauth-refresh-token/) · [moving Testing → Production](https://tech.queenofsandiego.com/posts/2026-05-06-2124.html)
- [Gmail IMAP/SMTP + App Password (2026)](https://smtpedia.com/gmail-email-settings-pop3-imap-smtp/) · [Google: transition from less secure apps](https://support.google.com/a/answer/14114704?hl=en)
- [Microsoft Graph OAuth for Outlook/M365](https://www.unipile.com/microsoft-graph-oauth-email/) · [Graph permissions reference](https://learn.microsoft.com/en-us/graph/permissions-reference) · [Graph throttling limits](https://learn.microsoft.com/en-us/graph/throttling-limits)
- [Outlook change notifications](https://learn.microsoft.com/en-us/graph/outlook-change-notifications-overview) · [delta query overview](https://learn.microsoft.com/en-us/graph/delta-query-overview)
- [Mail-Advanced.ReadWrite change (Dec 2026)](https://4sysops.com/archives/mail-advancedreadwrite-permissions-required-to-change-sensitive-email-properties-in-exchange-online-via-graph-api/)
- [EmailEngine licensing](https://learn.emailengine.app/docs/licensing) · [EmailEngine license text](https://github.com/postalsys/emailengine/blob/master/LICENSE_EMAILENGINE.txt)
- [webklex/php-imap](https://github.com/Webklex/laravel-imap) · [google-api-php-client](https://github.com/googleapis/google-api-php-client) · [microsoft/microsoft-graph](https://packagist.org/packages/microsoft/microsoft-graph) · [Laravel 13](https://laravel.com/docs/13.x/releases)

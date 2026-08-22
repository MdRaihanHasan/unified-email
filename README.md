# Unified Email

Gmail + Outlook-এর জন্য একটা **self-hosted, single-user** unified inbox — নিজের
জন্য বানানো Front-style email client। Laravel backend, provider API সরাসরি
(কোনো third-party email service নেই), Docker-এ চলে।

**Scope: শুধু নিজের ব্যবহার।** SaaS নয় — কোনো multi-tenancy, signup বা billing নেই।

## যে তিনটা account connect হবে

| Account | Path | কেন |
|---|---|---|
| Google Workspace (নিজের domain) | Gmail API, **Internal** OAuth app | verification লাগে না, token expire করে না |
| Personal `@gmail.com` | IMAP/SMTP + **App Password** | CASA verification ($540+/yr) এড়ানোর একমাত্র বাস্তব পথ |
| Personal Outlook.com | Microsoft Graph, `/common` app | free, publisher verification লাগে না |

## Goals

- তিন account-এর email একটা unified inbox-এ, thread-wise (cross-account thread merge সহ)
- App থেকেই send / reply / reply-all / forward
- নতুন email auto sync — **polling + IMAP IDLE**, কোনো public webhook endpoint ছাড়া
- Thread, attachment, read/unread, tag, full-text search
- (পরে, optional) contact timeline — CRM-lite

## Stack (proposed)

| Layer | Choice |
|---|---|
| Backend | PHP 8.3+ / Laravel 13, FrankenPHP (Octane) |
| Email layer | **সরাসরি provider API** — `google/apiclient`, `microsoft/microsoft-graph`, `webklex/php-imap` |
| DB | PostgreSQL 16 (JSONB + `tsvector` full-text) |
| Queue | Redis + `queue:work` |
| Sync | scheduler polling (Gmail `historyId`, Graph `delta`) + IMAP IDLE daemon |
| Search | Postgres full-text — আলাদা search engine নেই |
| Storage | local volume (attachments) |
| Frontend | Inertia.js + Vue 3 + TypeScript + Tailwind, TipTap composer |

License খরচ **$0**। EmailEngine ($1,450/yr) আর Google CASA ($540+/yr) দুইটাই এই
scope-এ অপ্রয়োজনীয় — কারণ ব্যাখ্যা করা আছে docs-এ।

## Docs

- [`docs/research-and-stack.md`](docs/research-and-stack.md) — scope, কেন EmailEngine বাদ,
  তিনটা auth path আর প্রতিটার কারণ, real-time strategy, খরচ, risk
- [`docs/architecture.md`](docs/architecture.md) — system design, `MailboxProvider`
  abstraction ও তিন adapter-এর তুলনা, Postgres schema, sync engine, send flow ও
  threading header, search, security, Docker Compose, roadmap
- [`docs/provider-setup.md`](docs/provider-setup.md) — তিনটা account-এর credential
  setup-এর ধাপে ধাপে guide + checklist

- [`docs/deployment.md`](docs/deployment.md) — কোথায় deploy করা যাবে (আর কেন Vercel-এ
  যাবে না), VPS/local/PaaS-এর তুলনা

## Quick start

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
# http://localhost:8000
```

IMAP account যোগ করার পরে IDLE daemon চালু করতে:

```bash
# .env-এ IDLE_ACCOUNT=you@gmail.com দিয়ে
docker compose --profile idle up -d
```

Docker ছাড়া local dev (Postgres 16 + Redis নিজে চালু থাকলে):

```bash
composer install && cp .env.example .env
php artisan key:generate && php artisan migrate
php artisan test
php artisan serve            # web
php artisan queue:work       # worker
php artisan schedule:work    # প্রতি মিনিটে delta poll
```

> Test-গুলো Postgres-এ চলে, SQLite-এ নয় — schema-তে generated `tsvector` column,
> GIN index আর `jsonb` আছে, যেগুলো SQLite বানাতে পারে না। `unified_email_test`
> database লাগবে।

## Status

**Phase 0 (foundations) সম্পূর্ণ** — ৩৭টা test pass, migration আসল Postgres 16-এ যাচাই করা।

দাঁড়িয়ে গেছে: schema, `MailboxProvider` contract + normalized DTO, তিন provider
adapter-এর client bootstrap, `ThreadResolver` (তিন tier, cross-account merge সহ),
`ReplyHeaders`, sync job orchestration (overlap lock + cursor expiry → full resync),
staleness watchdog, Docker stack।

বাকি: provider-দের আসল protocol call, backfill, send, IDLE daemon, UI —
[`docs/architecture.md`](docs/architecture.md)-এর roadmap দেখো। যে method এখনো
implement হয়নি সেটা exception ছোড়ে, খালি data ফেরত দেয় না।

পরের ধাপ: **Phase 1** — Graph adapter দিয়ে Outlook read-only end-to-end।

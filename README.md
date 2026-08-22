# Unified Email

Gmail + Outlook/Microsoft 365-এর জন্য একটা unified inbox SaaS — Front / HubSpot
Conversations-style email client, Laravel backend + EmailEngine email layer, পরে CRM
integration সহ।

## Goals

- Gmail এবং Outlook/M365 account connect করা (OAuth2)
- দুই provider-এর email একটা unified inbox-এ, thread-wise
- App থেকেই send / reply / reply-all / forward
- নতুন email real-time auto sync (webhook, polling নয়)
- Thread, attachment, read/unread, label, full-text search
- CRM-এর contact/lead/deal-এর সাথে email conversation attach করা

## Stack (proposed)

| Layer | Choice |
|---|---|
| Backend | PHP 8.3+ / Laravel 13 |
| Email layer | EmailEngine (self-hosted, Node + Redis) |
| DB | PostgreSQL 16+ |
| Queue | Redis + Laravel Horizon |
| Real-time | Laravel Reverb |
| Search | Meilisearch (Laravel Scout) |
| Storage | S3-compatible (R2 / MinIO) — attachment cache |
| Frontend | Inertia.js + Vue 3 + TypeScript + Tailwind, TipTap composer |

## Docs

- [`docs/research-and-stack.md`](docs/research-and-stack.md) — feasibility, build-vs-buy,
  provider compliance (Google CASA / Microsoft publisher verification), খরচ, risk
- [`docs/architecture.md`](docs/architecture.md) — system design, data model, sync ও send
  flow, threading strategy, search, CRM bridge, security, roadmap

## Status

Research ও architecture phase. এখনো কোনো application code নেই।

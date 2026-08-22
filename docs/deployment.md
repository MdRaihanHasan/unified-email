# Deployment — কোথায় চালানো যাবে, কোথায় যাবে না

---

## সংক্ষেপে: Vercel দিয়ে হবে না

Vercel এই app-এর জন্য কাজ করবে না — একটা কারণে নয়, চারটা কারণে। এগুলো config
দিয়ে ঠিক করার জিনিস নয়, platform-এর গঠনগত সীমা।

| যা দরকার | Vercel-এ কী হয় |
|---|---|
| **IMAP IDLE daemon** — personal Gmail-এ ঘণ্টার পর ঘণ্টা খোলা TCP connection | ❌ Vercel-এ persistent process রাখাই যায় না। সব কিছু isolated function invocation |
| **Queue worker** — backfill, send, body fetch | ❌ একই সমস্যা। function শেষ হলে process মরে যায় |
| **Backfill job** — হাজার হাজার message, কয়েক মিনিট লাগে | ❌ function timeout (Hobby 60s, Pro 300s max) |
| **PHP/Laravel** | ⚠️ শুধু community runtime (`vercel-community/php`), official নয় — কম extension, ছোট payload limit, ছোট timeout |
| **Attachment storage** | ❌ filesystem ephemeral, S3 লাগবে |
| **Cron প্রতি মিনিটে** | ⚠️ Hobby-তে দিনে একবার; Pro লাগবে |

উপরন্তু Postgres আর Redis দুইটাই বাইরে থেকে নিতে হবে। মানে "Vercel-এ deploy" আসলে
হয়ে দাঁড়াবে: Vercel + external Postgres + external Redis + queue-এর জন্য আলাদা
কোথাও একটা worker + IDLE-এর জন্য আরও একটা box। একটা $5 VPS-এর কাজ পাঁচ টুকরো করে
বেশি খরচে করা।

> Vercel frontend-এর জন্য দুর্দান্ত। এটা frontend নয় — এটা একটা stateful sync engine
> যার কাজই হলো long-lived connection ধরে রাখা।

---

## যেগুলো দিয়ে হবে

### ⭐ Option A — নিজের machine / NAS, Docker Compose  · $0

Personal tool-এর জন্য এটাই প্রথম choice।

```bash
docker compose up -d
# http://localhost:8000
```

- খরচ শূন্য, data ১০০% নিজের কাছে
- সব provider connection outbound, তাই কোনো port forward বা public IP লাগে না
- **শর্ত:** machine বন্ধ থাকলে sync হবে না। চালু করলে delta/IDLE ধরে আবার catch up
  করে নেবে (cursor DB-তে আছে), তাই email হারাবে না — শুধু দেরি হবে
- সারাক্ষণ চালু NAS/mini-PC/Raspberry Pi থাকলে এটাই আদর্শ

### ⭐ Option B — একটা ছোট VPS, একই Docker Compose  · ~$5/mo

সবসময় চালু থাকুক চাইলে এটাই সেরা value।

| Provider | Spec | Price |
|---|---|---|
| Hetzner CX22 | 2 vCPU / 4 GB / 40 GB | ~€4/mo |
| DigitalOcean | 1 vCPU / 2 GB | $12/mo (1 GB $6) |
| Vultr / Linode | 1 vCPU / 2 GB | ~$10-12/mo |

৩টা mailbox-এর জন্য 2 GB RAM যথেষ্ট, 4 GB আরামদায়ক। Deploy = `git pull && docker compose up -d --build`.

**নিরাপত্তা:** app-এর port publicly bind করবে না। হয় **Tailscale** (সবচেয়ে সহজ,
free, কোনো port খোলা লাগে না), নয় Caddy/nginx + TLS + strong auth। একজনের personal
mailbox — internet-এ খোলা রাখার কোনো কারণ নেই।

### Option C — Vercel-এর মতো DX চাইলে: VPS + Coolify / Dokploy  · ~$5/mo

উপরের সেই একই VPS-এ **Coolify** বসিয়ে দিলে git-push-to-deploy, auto TLS, log
viewer, env manager — Vercel-এর অনুভূতি, কিন্তু persistent process চলে আর box-টা নিজের।
এটাই "Vercel-এ deploy করতে চাই"-এর আসল উত্তর।

### Option D — Managed PaaS

| Platform | কাজ করবে? | মন্তব্য |
|---|---|---|
| **Laravel Cloud** | ✅ | Starter $5/mo, worker cluster-এ custom long-running process চালানো যায় (IDLE daemon), managed Postgres + Redis + scheduler। Laravel-native, সবচেয়ে কম ঝামেলা |
| **Railway** | ✅ | multi-service (web + worker + idle), managed PG/Redis, usage-based |
| **Render** | ✅ | background worker + cron + managed PG/Redis |
| **Fly.io** | ✅ | persistent process ও volume ভালোভাবে চলে |
| **Vercel / Netlify / Cloudflare Pages** | ❌ | serverless — উপরের কারণগুলো |
| **Shared cPanel hosting** | ❌ | long-running process বা Docker নেই |

Laravel Cloud সহজ, কিন্তু usage-based বিলিং-এ ২৪/৭ চলা worker + always-on IDLE process
মিলে $5 credit ছাড়িয়ে যেতে পারে। একজনের personal tool-এ **Option B-ই সবচেয়ে সস্তা ও
predictable**।

---

## সুপারিশ

```
সারাক্ষণ চালু machine/NAS আছে?  ──►  Option A (local Docker, $0)
                     না         ──►  Option B (Hetzner ~€4/mo + Tailscale)
   git-push deploy চাই?         ──►  Option C (একই VPS + Coolify)
```

দুইটার মধ্যে migration সহজ — একই `docker-compose.yml`, শুধু `.env` আর volume।
তাই **local Docker দিয়ে শুরু করো**; দরকার হলে পরে VPS-এ তুলবে।

---

## যদি সত্যিই serverless-এ চালাতেই হয়

তাহলে architecture বদলাতে হবে, এবং কিছু হারাতে হবে:

1. **IMAP IDLE বাদ** → personal Gmail-ও polling-এ (instant sync হারালো)
2. তিনটাই cron-driven polling, প্রতি মিনিটে
3. Backfill ছোট ছোট chunk-এ ভাঙতে হবে যাতে প্রতিটা function timeout-এর নিচে থাকে
4. Attachment S3-তে
5. PHP-এর official runtime নেই — Bref/Lambda-তে সরাতে হবে, Vercel-এ নয়

মানে বেশি কাজ, বেশি খরচ, কম feature। $5-এর VPS-এ এই সমস্যাগুলোর একটাও নেই।

---

## Sources

- [Vercel functions limitations](https://vercel.com/docs/functions/limitations) · [limits](https://vercel.com/docs/limits) · [runtimes](https://vercel.com/docs/functions/runtimes)
- [vercel-community/php runtime](https://github.com/vercel-community/php)
- [Vercel backend limitations analysis](https://northflank.com/blog/vercel-backend-limitations)
- [Laravel Cloud pricing](https://laravel.com/cloud/pricing) · [compute & worker clusters](https://cloud.laravel.com/docs/compute) · [queues](https://laravel.com/cloud/docs/queues)
- [Laravel Cloud $5 plan vs a $5 VPS](https://deploynix.io/blog/laravel-clouds-5-plan-vs-a-5-vps-the-real-cost-math)

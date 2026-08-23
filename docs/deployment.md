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
cp .env.example .env

# APP_KEY একবার বানাও। এটা harden করা আছে: entrypoint কখনো নিজে key generate
# করে না, কারণ প্রতিটা mailbox-এর refresh token এই key দিয়ে encrypt করা — boot-এ
# নতুন key বসালে সব credential অপাঠ্য হয়ে যেত, আর account গুলো connected দেখাত
# কিন্তু প্রথম sync-এই fail করত।
docker compose run --rm --no-deps app php artisan key:generate --show
# আউটপুটটা .env-এর APP_KEY=... এ বসাও

docker compose up -d --build          # migration app container নিজেই চালায়
docker compose exec app php artisan mail:user   # login বানাও
```

তারপর http://localhost:8000 — Settings → Connect a Gmail account।

**`APP_KEY`-এর backup অন্য কোথাও রাখো।** হারালে প্রতিটা mailbox আবার connect করতে হবে।

Container গুলো:

| Service | কাজ |
|---|---|
| `app` | FrankenPHP (Caddy + PHP এক process), :8000-এ loopback-এ bind |
| `worker` | `queue:work` — backfill, body fetch, send, flag push |
| `scheduler` | `schedule:work` — প্রতি মিনিটে delta poll |
| `postgres` | 16-alpine |
| `redis` | queue + cache |
| `imap-idle` | শুধু plain IMAP mailbox-এর জন্য; Gmail-এ লাগে না, তাই profile-এর পিছনে |

দুইটা জিনিস ইচ্ছে করে করা:

- **`storage/app` volume `app` আর `worker` ভাগ করে।** Draft-এ attach করা file
  web container লেখে, কিন্তু send করার সময় worker পড়ে — আলাদা volume হলে প্রতিটা
  attachment হারিয়ে যেত।
- **শুধু `app` migration চালায়** (`RUN_MIGRATIONS=true`)। Worker আর scheduler একই
  migration-এ race করলে schema আধা-প্রয়োগ হয়ে বসে থাকতে পারে।

**শর্ত:** machine বন্ধ থাকলে sync হবে না। চালু করলে cursor DB-তে আছে, catch up করে
নেবে — email হারাবে না, শুধু দেরি হবে।

দৈনন্দিন command:

```bash
docker compose logs -f app worker      # কী হচ্ছে দেখতে
docker compose exec app php artisan mail:watchdog   # কোনো account পিছিয়ে আছে?
docker compose exec app php artisan mail:sync       # এখনই sync
docker compose down                    # থামাও (data volume-এ থাকে)
docker compose up -d --build           # code বদলের পর
```

### ⭐ Option B — একটা ছোট VPS, একই Docker Compose  · ~$5/mo

সবসময় চালু থাকুক চাইলে এটাই সেরা value।

| Provider | Spec | Price |
|---|---|---|
| Hetzner CX22 | 2 vCPU / 4 GB / 40 GB | ~€4/mo |
| DigitalOcean | 1 vCPU / 2 GB | $12/mo (1 GB $6) |
| Vultr / Linode | 1 vCPU / 2 GB | ~$10-12/mo |

৩টা mailbox-এ 2 GB RAM যথেষ্ট, 4 GB আরামদায়ক। Deploy = `git pull && docker compose up -d --build`.

দুইটা জিনিস বদলাতে হবে:

1. **`APP_URL`** — Google-এর redirect URI hostname-সহ hubohu মিলতে হবে, তাই নতুন
   URL-টা Cloud Console-এর Authorized redirect URIs-এ যোগ করো
   (`https://mail.example.com/gmail/callback`)।
2. **App port publicly bind করবে না।** Compose ইচ্ছে করেই `127.0.0.1`-এ bind করে।
   হয় **Tailscale** (সবচেয়ে সহজ, free, কোনো port খোলা লাগে না), নয় Caddy/nginx
   reverse proxy + TLS। একজনের personal mailbox internet-এ খোলা রাখার কারণ নেই।

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

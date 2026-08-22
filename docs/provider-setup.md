# Provider Setup — তিনটা account connect করার ধাপ

এই কাজগুলো code লেখার আগেই করে রাখা ভালো, কারণ credential না থাকলে Phase 1-এ কিছু
test করা যাবে না। তিনটাই ~৩০-৪৫ মিনিটের কাজ, কোনো টাকা লাগবে না, কোনো review
অপেক্ষা করতে হবে না।

---

## 1. Personal Outlook.com → Microsoft Graph

সবচেয়ে সহজ, তাই Phase 1-এ এটাই আগে।

1. https://entra.microsoft.com → **App registrations** → **New registration**
   - Name: `unified-email`
   - Supported account types: **Accounts in any organizational directory and personal
     Microsoft accounts** (`/common`) — শুধু Outlook.com হলে *Personal Microsoft accounts only*-ও চলবে
   - Redirect URI: **Web** → `http://localhost:8000/oauth/microsoft/callback`
2. **Certificates & secrets** → New client secret → value কপি করে রাখো (আর দেখাবে না)
3. **API permissions** → Microsoft Graph → **Delegated**:
   - `Mail.ReadWrite`
   - `Mail.Send`
   - `offline_access` ← **এটা ছাড়া refresh token পাবে না**
   - `User.Read`
4. `.env`:
   ```
   MS_CLIENT_ID=
   MS_CLIENT_SECRET=
   MS_TENANT=common
   MS_REDIRECT_URI=http://localhost:8000/oauth/microsoft/callback
   ```

প্রথমবার consent screen-এ **"unverified publisher"** warning দেখাবে — নিজের app,
তাই accept করে দাও। Publisher verification লাগবে না।

Endpoints যেগুলো লাগবে:
`GET /me/mailFolders` · `GET /me/mailFolders/{id}/messages/delta` ·
`GET /me/messages/{id}/$value` (raw MIME) · `POST /me/sendMail` ·
`POST /me/messages/{id}/createReply` · `PATCH /me/messages/{id}` (isRead, flag)

---

## 2. Google Workspace → Gmail API (Internal app)

**গুরুত্বপূর্ণ:** Google Cloud project-টা **Workspace domain-এর account দিয়ে** বানাতে হবে,
নইলে consent screen-এ "Internal" option-ই আসবে না।

1. https://console.cloud.google.com → নতুন project (Workspace account দিয়ে login করে)
2. **APIs & Services** → Library → **Gmail API** → Enable
3. **OAuth consent screen** → User type: **Internal** ⭐
   - এই একটা setting-ই CASA verification, ৭ দিনের token expiry আর ১০০-user cap তিনটাই বাদ দেয়
4. **Credentials** → Create credentials → **OAuth client ID** → Web application
   - Redirect URI: `http://localhost:8000/oauth/google/callback`
5. Scopes:
   ```
   https://www.googleapis.com/auth/gmail.modify
   https://www.googleapis.com/auth/gmail.send
   https://www.googleapis.com/auth/gmail.labels
   ```
6. `.env`:
   ```
   GOOGLE_CLIENT_ID=
   GOOGLE_CLIENT_SECRET=
   GOOGLE_REDIRECT_URI=http://localhost:8000/oauth/google/callback
   ```

OAuth flow-এ `access_type=offline` আর `prompt=consent` দিতে হবে — নইলে refresh token আসবে না।

Endpoints: `users.getProfile` · `users.labels.list` · `users.messages.list` ·
`users.messages.get?format=raw` · `users.history.list?startHistoryId=` ·
`users.messages.send` · `users.messages.modify` (label add/remove)

> `historyId` সংরক্ষণ করে রাখতে হবে। খুব পুরোনো হলে API **404** দেবে → full resync।

---

## 3. Personal @gmail.com → App Password (IMAP/SMTP)

1. https://myaccount.google.com/security → **2-Step Verification** on করো (আগে না থাকলে)
2. https://myaccount.google.com/apppasswords → app password generate → **16 character** কপি করো
3. Gmail settings → Forwarding and POP/IMAP → **IMAP enable** (এখন default-এ on থাকে)
4. `.env` নয়, DB-তে (encrypted) — connect UI দিয়ে ঢুকবে:
   ```
   IMAP  imap.gmail.com : 993  SSL
   SMTP  smtp.gmail.com : 465  SSL
   user  <you>@gmail.com
   pass  <16-char app password>
   ```

মনে রাখার জিনিস:
- Google account-এর main password বদলালে **app password revoke হয়ে যায়** → re-enter করতে হবে
- `smtp.gmail.com` দিয়ে পাঠালে Gmail **নিজেই** Sent-এ copy রাখে — manual `APPEND` করলে duplicate
- Gmail IMAP-এ folder আসলে label: `[Gmail]/Sent Mail`, `[Gmail]/All Mail`, `[Gmail]/Trash`
- `All Mail` আর `INBOX` একই message দেখাবে — backfill-এ শুধু একটা বেছে নিতে হবে, নইলে দ্বিগুণ কাজ
- IDLE-এ Gmail নিষ্ক্রিয় connection ~২৯ মিনিটে drop করে → প্রতি ~২৫ মিনিটে re-IDLE করতে হবে

---

## Checklist

- [ ] Entra app registration + client secret + delegated permissions
- [ ] Google Cloud project (Workspace account দিয়ে), Gmail API enabled, consent screen = **Internal**
- [ ] Google OAuth client + redirect URI
- [ ] Personal Gmail-এ 2FA + app password
- [ ] `.env` ভরা, `.env` git-এ নেই
- [ ] `APP_KEY` generate + **আলাদা জায়গায় backup** (হারালে সব credential অপাঠ্য)

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

## 2. Gmail — personal আর Workspace, একই OAuth client-এ

> **সংশোধন:** আগে এই doc-এ লেখা ছিল Workspace-এর জন্য "Internal" app আর personal
> Gmail-এর জন্য App Password। ওটা ভুল ছিল। **একটাই External app, "In production"
> status-এ — দুইটাই চলে।** কারণ:
>
> - ৭ দিনে refresh token মরে যাওয়ার নিয়মটা শুধু **"Testing"** status-এর। Publish
>   করলে token আর expire করে না।
> - Unverified app production-এও restricted scope নিয়ে **চলে**। খরচ: প্রতি mailbox-এ
>   একবার "unverified app" warning screen, আর ১০০ new-user cap। একজনের tool-এ
>   দুইটাই অপ্রাসঙ্গিক — আর CASA assessment (বছরে $540+) লাগে না।
>
> ⚠️ ১০০-user cap Cloud project-এর উপর **স্থায়ী**, নতুন client id বানিয়েও reset হয় না।
> এটা শুধু তখনই সমস্যা যদি কখনো এটা একজনের tool না থাকে।

### ধাপ

1. https://console.cloud.google.com → নতুন project
2. **APIs & Services** → Library → **Gmail API** → Enable
3. **OAuth consent screen**
   - User type: **External**
   - App name, support email, developer email ভরো
   - **Publishing status: "In production"** ← এই একটা setting-ই আসল। Testing-এ রেখে
     দিলে প্রতি সপ্তাহে সব account re-connect করতে হবে
   - Verification-এর জন্য submit করার দরকার **নেই** — warning screen নিয়েই চলবে
4. **Credentials** → Create credentials → **OAuth client ID** → Web application
   - Authorized redirect URI: `http://localhost:8000/gmail/callback`
   - (VPS-এ চালালে সেই URL-ও যোগ করো — Google exact match চায়)
5. Scopes — app নিজেই চায়, consent screen-এ আলাদা করে যোগ করার দরকার নেই:
   ```
   https://www.googleapis.com/auth/gmail.modify   (পড়া, flag, label)
   https://www.googleapis.com/auth/gmail.send     (পাঠানো)
   ```
   ইচ্ছে করেই `https://mail.google.com/` নেই (পুরো IMAP-স্তরের access, দরকার নেই) আর
   `gmail.labels`-ও নেই (ওটা label definition বানানোর জন্য, আমরা করি না)।
6. `.env`:
   ```
   GOOGLE_CLIENT_ID=
   GOOGLE_CLIENT_SECRET=
   GOOGLE_REDIRECT_URI=http://localhost:8000/gmail/callback
   ```

### Connect করা

App চালিয়ে **Settings → Connect a Gmail account**। প্রতিটা mailbox-এর জন্য একবার
করো — personal আর Workspace, একই button।

প্রথমবার Google **"Google hasn't verified this app"** দেখাবে →
*Advanced* → *Go to … (unsafe)*। App তোমার নিজের, তাই এটা নিরাপদ।

⚠️ **Refresh token না এলে** app connect করতে অস্বীকার করবে (অর্ধেক-connected account
রাখার চেয়ে ভালো)। এটা হয় যদি Google আগের একটা grant পুনর্ব্যবহার করে। সমাধান:
https://myaccount.google.com/permissions → app-টা remove করো → আবার connect করো।

### পরে যা মনে রাখতে হবে

- Google account-এর password বদলালে refresh token টেকে, কিন্তু **app permission
  revoke করলে** যায় — তখন Settings-এ "Reconnect" দেখাবে
- `historyId` সংরক্ষিত থাকে; খুব পুরোনো হলে Gmail **404** দেয় → app নিজেই full
  resync করে নেয়

## Checklist

- [ ] Entra app registration + client secret + delegated permissions
- [ ] Google Cloud project, Gmail API enabled, consent screen **External** +
      publishing status **"In production"**
- [ ] Google OAuth client + redirect URI `…/gmail/callback`
- [ ] প্রতিটা Gmail mailbox Settings থেকে connect করা (warning screen পেরিয়ে)
- [ ] `.env` ভরা, `.env` git-এ নেই
- [ ] `APP_KEY` generate + **আলাদা জায়গায় backup** (হারালে সব credential অপাঠ্য)

# Deploy Backend to Railway (step-by-step)

## Before you start

1. **GitHub account** — Railway deploys easiest from a Git repo.
2. Push this project to GitHub (see Step 0 below if you have not yet).
3. Have your local `Backend/.env` open — you will copy values into Railway (never commit `.env`).

---

## Step 0 — Push code to GitHub (if needed)

In PowerShell, from `c:\Users\Licht\WebdevProj`:

```powershell
git init
git add .
git commit -m "Initial commit for deployment"
```

Create a new repo on GitHub (empty, no README), then:

```powershell
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPO.git
git branch -M main
git push -u origin main
```

---

## Step 1 — Create Railway project

1. Go to [https://railway.app](https://railway.app) and sign in (GitHub login is easiest).
2. Click **New Project**.
3. Choose **Deploy from GitHub repo**.
4. Select your repository.
5. Railway creates a service — click it to open settings.

---

## Step 2 — Set root directory to `Backend`

Railway must build only the Symfony app, not the whole monorepo.

1. Open your service → **Settings**.
2. Find **Root Directory** (or **Source** → Root Directory).
3. Set it to: `Backend`
4. Save. Railway will redeploy.

Your repo already includes `Backend/railway.toml` (build + start commands).

---

## Step 3 — Add MySQL database

1. In the same Railway project, click **+ New** → **Database** → **MySQL**.
2. Wait until MySQL is running.
3. Click the **MySQL** service → **Variables** or **Connect**.
4. Copy `MYSQL_URL` or the full `DATABASE_URL` (Railway often provides `DATABASE_URL`).

### Link database to Backend service

1. Open your **Backend** (Symfony) service → **Variables**.
2. Click **Add Reference** (or **Variable Reference**) and attach MySQL’s `DATABASE_URL` to the backend service.

   If you paste manually, it looks like:

   ```
   mysql://root:PASSWORD@HOST:PORT/railway
   ```

3. Symfony also needs a server version in the URL. Append if missing:

   ```
   ?serverVersion=8.0.32&charset=utf8mb4
   ```

   Example:

   ```
   DATABASE_URL=mysql://root:xxxxx@mysql.railway.internal:3306/railway?serverVersion=8.0.32&charset=utf8mb4
   ```

---

## Step 4 — Set environment variables (Backend service)

In **Backend service → Variables**, add these (copy secrets from your local `.env`):

| Variable | Value |
|----------|--------|
| `APP_ENV` | `prod` |
| `APP_DEBUG` | `0` |
| `APP_SECRET` | from local `APP_SECRET` |
| `JWT_SECRET` | from local `JWT_SECRET` |
| `JWT_PASSPHRASE` | from local `JWT_PASSPHRASE` |
| `DATABASE_URL` | reference from MySQL (Step 3) |
| `FRONTEND_URL` | `http://localhost:3000` for now (change to Vercel URL later) |
| `FRONTEND_URL` | Your **Vercel** URL, e.g. `https://your-app.vercel.app` (no trailing slash) |
| `DEFAULT_URI` | Your **Railway** public URL, e.g. `https://backend-webdev-production.up.railway.app` |
| `GOOGLE_CLIENT_ID` | From Google Cloud Console |
| `GOOGLE_CLIENT_SECRET` | From Google Cloud Console |
| `GOOGLE_REDIRECT_URI` | `https://YOUR-RAILWAY-DOMAIN/connect/google/check` (exact match in Google Console) |
| `MAILER_DSN` | Brevo SMTP with timeout, e.g. `smtp://user@smtp-brevo.com:KEY@smtp-relay.brevo.com:587?encryption=tls&timeout=10` |
| `MAILER_FROM_ADDRESS` | Sender verified in Brevo |
| `MAILER_FROM_NAME` | `"K-Dream Merchandise"` (quotes required — spaces break Symfony `.env`) |
| `CONTACT_NOTIFY_EMAIL` | Inbox for contact form notifications |
| `CONTACT_NOTIFY_EMAIL` | from local `.env` |
| `ONESIGNAL_APP_ID` | optional |
| `ONESIGNAL_API_KEY` | optional |
| `WORKERMAN_INTERNAL_URL` | `http://localhost:8091` (OK if WebSocket not deployed yet) |

**Lexik JWT file paths** (keys are generated at build time):

| Variable | Value |
|----------|--------|
| `JWT_SECRET_KEY` | `%kernel.project_dir%/config/jwt/private.pem` |
| `JWT_PUBLIC_KEY` | `%kernel.project_dir%/config/jwt/public.pem` |

Do **not** upload your local `.env` file. Set variables only in the Railway UI.

---

## Step 5 — Networking (public URL)

1. Backend service → **Settings** → **Networking**.
2. Click **Generate Domain** (e.g. `your-app-production.up.railway.app`).
3. Copy that URL — you will use it for API calls and Google OAuth.

---

## Step 6 — Deploy and watch logs

1. **Deployments** tab → latest deploy should be **Building** then **Active**.
2. Open **Deploy Logs** and confirm you see:
   - `composer install`
   - `Running database migrations...`
   - `Starting PHP built-in server on 0.0.0.0:...`

### If deploy fails

| Symptom | Fix |
|---------|-----|
| `composer install` fails | Check PHP version in logs; repo targets PHP 8.2+ |
| Database connection refused | `DATABASE_URL` not linked or wrong host; use Railway **reference** from MySQL |
| Migration errors | Open MySQL logs; ensure DB exists and URL has `serverVersion=8.0.32` |
| JWT / pem errors | Redeploy; build runs `lexik:jwt:generate-keypair` |
| Health check failing | Wait 2 min; open `https://YOUR-DOMAIN.up.railway.app/api/health` in browser |

---

## Step 7 — Test the API

In browser or PowerShell:

```powershell
curl https://YOUR-DOMAIN.up.railway.app/api/health
```

Expected:

```json
{"status":"ok"}
```

Test login (replace email/password):

```powershell
curl -X POST https://YOUR-DOMAIN.up.railway.app/api/login `
  -H "Content-Type: application/json" `
  -d '{"email":"your@email.com","password":"yourpassword"}'
```

You should get JSON with `token`, `roles`, `user`.

---

## Step 8 — Google OAuth + Brevo email

**Check configuration after deploy:**

```text
GET https://YOUR-RAILWAY-DOMAIN/api/health/integrations
```

If `status` is `misconfigured`, follow the `hints` array in the JSON response.

### Google OAuth

1. [Google Cloud Console](https://console.cloud.google.com) → **APIs & Services** → **Credentials**.

---

## Optional — Deploy WebSocket (Workerman) on Railway (real-time)

Your API service (`backend-webdev`) does **not** run WebSocket. To enable real-time updates for mobile/web:

### 1) Create a second Railway service

1. In the **same Railway project**, click **+ New** → **Service** → **Deploy from GitHub repo**
2. Choose the same repo
3. Set **Root Directory** to `Backend`
4. Name it something like: `backend-webdev-ws`

### 2) Set the WS service start command

Set the service start command to run Workerman:

```bash
php bin/websocket-server.php start
```

Railway will provide a `PORT` automatically. The WebSocket server uses `PORT` for the public `wss://` listener.

### 3) Configure private push (API → WS service)

On **WS service** variables:

- **`WORKERMAN_INTERNAL_TOKEN`**: set a random secret (e.g. 32 chars)
- (optional) `WORKERMAN_INTERNAL_HOST=0.0.0.0`
- (optional) `WORKERMAN_INTERNAL_PORT=8091`

On **API service** (`backend-webdev`) variables:

- **`WORKERMAN_INTERNAL_URL`**: set to the WS service private URL, port 8091  
  Example shape:
  - `http://backend-webdev-ws.railway.internal:8091`
- **`WORKERMAN_INTERNAL_TOKEN`**: same value as the WS service token

### 4) Mobile app WebSocket URL

In `Kpop/src/config/api.ts`, set:

- `PRODUCTION_WS_URL = 'wss://YOUR-WS-SERVICE.up.railway.app'`

Then rebuild the release APK.
2. Open your OAuth 2.0 Client.
3. **Authorized redirect URIs** → add:

   ```
   https://YOUR-DOMAIN.up.railway.app/connect/google/check
   ```

4. Save.

After Vercel is deployed, set `FRONTEND_URL` to your Vercel URL and redeploy Railway.

---

## Step 9 — Save your Railway URL

Write it down — you will need it for Vercel:

```
REACT_APP_API_URL=https://YOUR-DOMAIN.up.railway.app
```

(no `/api` at the end; the frontend adds `/api` automatically)

---

## Optional — Deploy without GitHub (Railway CLI)

```powershell
npm i -g @railway/cli
railway login
cd c:\Users\Licht\WebdevProj\Backend
railway init
railway add --database mysql
railway up
```

Then set the same variables in the dashboard.

---

## Product images note

Uploads are stored on the server disk. Railway may **wipe files on redeploy**. For a class demo, re-upload images after redeploy, or use cloud storage later.

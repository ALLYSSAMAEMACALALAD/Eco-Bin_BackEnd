# Eco-Bin

Smart waste collection and municipal recycling rewards, built on the **LavaLust** PHP MVC framework (backend) and **React + Vite** (frontend).

## Roles and login

Login is JWT-based through LavaLust's API helper. Access tokens last one hour and refresh tokens rotate on every refresh. On each request the server reads the user's role from the database, not from the token, and checks it against the endpoint's allowed roles.

| Role | Dashboard | Main functions |
|---|---|---|
| **resident** | `/resident` | Self sign-up, barangay collection schedule, QR eco-ID, points balance, reward redemption and PDF certificate, illegal dumping reports with photo and ticket tracking |
| **collector** | `/collector` | Assigned routes and stops, live truck location, mark stops collected, log recyclable drop-offs by eco-ID (one credit per transaction), update dumping tickets |
| **admin** | `/admin` | Schedules and SMS/email reminders, routes and collector assignment, redemption approval, dumping ticket queue, environmental analytics, staff account creation, audit log |

Residents can only sign up themselves. Collector and admin accounts are created by an admin.

## Demo accounts (development only)

Migration `005` creates these when `APP_ENV=development`. Change or delete them before deploying.

| Role | Email | Password |
|---|---|---|
| Admin | admin@ecobin.test | Admin@12345 |
| Collector | collector@ecobin.test | Collector@123 |
| Resident | resident@ecobin.test | Resident@123 |

## Project layout

```
app/config/        routes.php, eco.php (points, storage, SMS), api.php (JWT), migration.php
app/controllers/   Auth, Schedules, Dispatch, Dropoffs, Rewards, Dumping, Analytics, Admin, Migrate (CLI)
app/models/        User, Schedule, Dispatch, Dropoff, Reward, Dumping, Analytics, Audit models
app/helpers/       eco_helper.php (role guard, DB shortcuts, audit, SMS)
app/migrations/    003 extends users table; 004 creates Eco-Bin tables and seeds rewards and a hub
frontend/          React + Vite app (src/pages/resident|collector|admin)
scheme/            LavaLust core (do not edit)
public/            front controller (index.php) and uploads
```

## Setup

**1. Backend**

```bash
export DB_HOST=127.0.0.1 DB_USER=root DB_PASSWORD=... DB_NAME=ecobin
export JWT_SECRET=$(php -r 'echo bin2hex(random_bytes(32));')
export REFRESH_TOKEN_KEY=$(php -r 'echo bin2hex(random_bytes(32));')
# create the database first:  CREATE DATABASE ecobin CHARACTER SET utf8mb4;
php public/index.php migrate          # runs pending migrations
php -S localhost:8080 -t public public/index.php
```

PHP 8.2 or newer, with the `pdo_mysql`, `curl` and `fileinfo` extensions.
If the database contains the older Eco-Bin schema, back it up before migrating. Migration 003 archives its original tables as `legacy_*`, and migration 006 imports compatible records into the current schema; the archived tables are retained.
The Docker image runs pending migrations before Apache starts. For other production setups, run `php public/index.php migrate` after configuring the database and before serving requests.

**2. Frontend**

```bash
cd frontend
npm install
npm run dev        # http://localhost:5173, proxies /api and /uploads to :8080
```

If the backend uses another local URL, set `ECO_BIN_API_TARGET` before starting Vite (for example, `http://localhost:8000`).

For a separately deployed frontend and backend, set the frontend build variable `VITE_API_URL` to the backend origin (for example, `https://eco-bin-backend.onrender.com`). Set the backend `CORS_ORIGIN` environment variable to the exact frontend origin (for example, `https://eco-bin-front-end-5cv3.vercel.app`, without a trailing slash). Multiple origins can be comma-separated.

**3. First admin**

Register an account at `/register`, then promote it once:

```sql
UPDATE users SET role = 'admin' WHERE email = 'you@example.com';
```

Create collectors from **Admin → Users & staff**.

## Security

- Passwords are hashed with bcrypt. The API has per-IP rate limiting on login and registration.
- Every protected endpoint checks the user's role on the server. Refused attempts are written to the audit log.
- Drop-off credits require an `Idempotency-Key` header. A repeated scan returns 409 and adds no points.
- Redemptions run inside a transaction with a row lock, so points cannot be spent twice.
- Sensitive actions (logins, drop-offs, redemptions, status changes, staff creation) are audited.

## Integrations

- **Google Maps:** truck location and directions use Google Maps links and embeds, which need no API key.
- **QR codes:** `qrcode.react` renders each resident's eco-ID.
- **PDF:** `jspdf` renders the reward certificate and green-impact summary in the browser.
- **SMS:** Semaphore is wired in (`SMS_PROVIDER=semaphore`). Twilio is not finished. Email reminders are queued in the `notifications` table only.
- **Photos:** Cloudinary unsigned upload when `CLOUDINARY_*` is set; otherwise saved to `public/uploads/`.

## Status and known gaps

- The PHP code and React code were written and syntax-checked. The API was smoke-tested for routing, and the CLI migration runner was confirmed to dispatch. The full flow has **not** been run against a live MySQL database, and the frontend has **not** been compiled with Vite.
- Truck tracking refreshes when the collector presses "Share truck location" (no WebSocket).
- Drop-off logging takes a typed or pasted eco-ID. A camera QR scanner is not included.

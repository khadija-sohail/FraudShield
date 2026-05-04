# FraudShield Project Documentation

## What This Project Does

FraudShield is a PHP + MySQL fraud operations dashboard that simulates a financial fraud monitoring workflow:

- Ingest and score transactions
- Surface alerts in a queue
- Track investigation cases and SLAs
- Support analyst/admin workflows
- Render KPI and chart analytics for operations monitoring

The frontend is a single HTML app. The backend is a single PHP API file (`db.php`) that serves all API routes via `?action=...`.

---

## How It Runs (Request/Data Flow)

1. Browser opens `http://localhost:8000`.
2. `index.php` serves `fraud_dashboardd.html`.
3. Frontend JavaScript calls `db.php?action=<name>` for data and updates.
4. `db.php` connects to MySQL, ensures schema/views/procedures/triggers exist, and seeds CSV demo data on first run.
5. API responses are returned as JSON and rendered into tables/charts.

---

## Run On Localhost

### Environment used here

- OS: Windows
- PHP executable detected at `C:\xampp\php\php.exe`
- Project root: `C:\Users\hp\FraudShield`

### Start server

```powershell
cd C:\Users\hp\FraudShield
& "C:\xampp\php\php.exe" -S localhost:8000
```

Open:

- `http://localhost:8000`

### Verification completed

The app endpoint was verified with:

```powershell
curl.exe -I http://localhost:8000
```

and returned `HTTP/1.1 200 OK`.

### Notes

- `php` and `docker` are not on PATH in this environment, so direct `php -S ...` and Docker commands fail unless full executable paths are used.
- Full app functionality (API-backed data) requires a running MySQL server reachable by `db.php` credentials.

---

## API Overview (`db.php?action=...`)

Main actions exposed:

- Auth/session: `session`, `login`, `logout`, `signup`
- Dashboard: `stats`, `charts`
- Data lists: `transactions`, `cases`, `alerts`, `customer`
- Role-specific panels: `admin`, `analyst`
- Writes/mutations: `assign_task`, `update_task`, `insert_transaction`, `insert_case`, `insert_alert`, `update_alert`, `update_setting`, `update_rule`, `save_note`

The API returns JSON and is consumed directly by frontend `fetch()` calls.

---

## File-by-File Guide

### Core application files

- `index.php`
  - Lightweight HTTP entrypoint/router.
  - Routes API requests (`/db.php` or `/api...`) to `db.php`.
  - Serves `fraud_dashboardd.html` for all other routes.

- `db.php`
  - Main backend service and API controller.
  - Defines DB connection config from env vars (`MYSQL*` / `DB_*`).
  - Initializes schema/tables and MySQL logic (views/procedures/triggers) at startup.
  - Seeds demo data and exposes all `?action=` endpoints for the UI.
  - Implements auth/session handling and admin/analyst operations.

- `fraud_dashboardd.html`
  - Single-page frontend UI (dashboard, transactions, alerts, cases, analyst/admin views).
  - Contains styling, layout, and all client-side JavaScript.
  - Calls `db.php?action=...` using `fetch`.
  - Renders charts/tables and handles filtering, pagination, and user interactions.

- `fraudshield_schema.sql`
  - SQL-first version of schema setup.
  - Creates core tables, reporting views, stored procedures, and triggers.
  - Useful for manual DB provisioning/inspection outside auto-init behavior in `db.php`.

### Data seed files

- `transactions.csv`
  - Demo transaction dataset with IDs, customer details, payment/channel info, score, risk, and status.
  - Seeded into `transactions` table.

- `cases.csv`
  - Demo investigation case dataset including priority, fraud type, assigned analyst, SLA, and resolution fields.
  - Seeded into `cases` table.

- `alerts.csv`
  - Demo alert queue dataset including severity, score, queue status, and open duration.
  - Seeded into `alerts` table.

### Configuration and deployment files

- `.env.example`
  - Example environment values for local and hosted MySQL connections.
  - Shows `DB_*`, `MYSQL*`, and URL-based connection options.

- `Dockerfile`
  - Container image definition using `php:8.3-cli`.
  - Installs `pdo_mysql`, copies app, exposes port `8080`, runs PHP built-in server.

- `.dockerignore`
  - Excludes local/irrelevant files from Docker build context (`.git`, logs, local `.env`, etc.).

### Documentation/legal files

- `README.md`
  - Main project overview, feature list, architecture, setup, and roadmap.

- `DEPLOYMENT.md`
  - Cloud deployment guidance (Railway/Render + MySQL).

- `LICENSE`
  - MIT license terms for project usage and distribution.

---

## Important Operational Details

- Database compatibility: backend is MySQL-specific (PDO MySQL + SQL views + stored procedures + triggers).
- Auto-create behavior: `DB_AUTO_CREATE=true` allows DB/schema bootstrap in local-style setups.
- Sessions: login state is PHP session-based; admin-only actions enforce role checks.

---

## Quick Start Checklist

1. Ensure MySQL is running and credentials in env/defaults are correct.
2. Start PHP server from project root.
3. Open `http://localhost:8000`.
4. Log in with seeded admin user from project docs, then verify:
   - dashboard KPIs load,
   - transaction/case/alert tables populate,
   - create/update actions return success.


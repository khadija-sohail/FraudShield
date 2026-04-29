<img width="1919" height="862" alt="fraud" src="https://github.com/user-attachments/assets/092e2e7e-ea9e-4f79-bbba-c62b3b47d4bf" /># 🛡️ FraudShield — Fraud Detection & Case Management Dashboard

> A full-stack fraud detection and case management platform built with PHP, MySQL, and JavaScript. Designed to replicate the core workflows of real financial crime monitoring systems — transaction scoring, alert queuing, case investigation, analyst task management, and admin controls — dashboard.


![Alt text](path/to/your/Fraud.png)
---

## 📑 Table of Contents

- Overview
- Features
- Tech Stack
- Database Architecture
- Project Structure
- Getting Started
- How Fraud Scoring Works
- Real-World References & Inspiration
- Fraud Techniques Researched
- Roadmap — Future Enhancements
- License

---

## Overview

FraudShield simulates an analyst fraud operations center. It covers the full lifecycle of a suspicious financial transaction — from the moment it hits the system with a risk score, through alert creation and analyst assignment, all the way to case resolution and recovery tracking.

The project was built to understand how real fraud detection systems work in practice: how banks and fintechs triage risk, manage SLA-bound cases, track fraud losses vs. recoveries, and coordinate analyst teams — without relying on third-party fraud APIs.

---

## Features

### 🔴 Live Dashboard

- KPI cards: total transactions, fraud events, blocked count, open cases, critical alerts, escalations today
- 8 real-time charts (fraud types, daily trend, loss vs recovery, by-channel, weekly cases, monthly cases, recovery rate, analyst performance)
- Auto-refreshing data pulled from MySQL views

### 💳 Transaction Monitor

- Full transaction table with pagination, search, and filters (method, risk level, status, min score)
- Risk classification: Critical / High / Medium / Low based on fraud score thresholds
- Status engine: Blocked / Flagged / Under Review / Cleared
- Manual transaction insertion via stored procedure

### 🚨 Alert Queue

- Multi-severity alert management: Critical, High, Medium, Low
- Queue statuses: New, Open, Escalated, Resolved, Pending, Investigating
- One-click status updates with audit trail

### 📁 Case Management

- Full case lifecycle: Open → Under Review → Investigating → Escalated → Closed
- SLA tracking per priority (Critical: 4h, High: 12h, Medium: 48h, Low: 120h)
- Fraud amount vs. recovered amount tracking
- Analyst assignment via stored procedures

### 👤 Customer Intelligence

- Per-customer transaction history (last 40 transactions)
- 30-day fraud score trend chart
- Hourly activity heatmap
- Active case linkage

### 🔐 Admin Panel

- User management (analysts / admins / managers)
- Fraud rule engine with threshold configuration
- App settings management
- Audit log viewer
- Login history tracking
- Task assignment to analysts

### 👨‍💼 Analyst Workspace

- Personal case queue
- Task board with Assigned / In Progress / Done states
- Note-taking per case and per customer

---

## Tech Stack

| Layer | Technology |
|------|------------|
| Frontend | HTML5, CSS3, JavaScript, Chart.js |
| Backend | PHP (single-file API pattern) |
| Database | MySQL with Views, Stored Procedures, and Triggers |
| Fonts | Google Fonts (Syne, JetBrains Mono, Space Mono) |
| Icons | Font Awesome 6.4 |

---

## Database Architecture

The schema (`fraudshield_schema.sql`) contains 10 tables, 12 views, 4 stored procedures, and 3 triggers.

```text
fraudshield/
├── Tables
│   ├── users              — Analyst / admin / manager accounts
│   ├── transactions       — All financial transactions with risk scores
│   ├── cases              — Fraud investigation cases
│   ├── alerts             — Real-time alert queue
│   ├── fraud_rules        — Configurable detection rule thresholds
│   ├── app_settings       — Key-value system settings
│   ├── audit_log          — Full action history
│   ├── analyst_notes      — Per-case and per-customer notes
│   ├── login_history      — Auth event tracking
│   └── analyst_tasks      — Task assignments
│
├── Views (reporting layer)
│   ├── v_dashboard_stats       — Aggregated KPI data
│   ├── v_chart_fraud_types     — Cases grouped by fraud type
│   ├── v_chart_daily_trend     — 30-day transaction + fraud count
│   ├── v_chart_loss_recovery   — Monthly fraud loss vs recovery ($k)
│   ├── v_chart_by_channel      — Fraud by payment method
│   ├── v_cases_weekly          — Weekly case opened vs resolved
│   ├── v_recovery_rate         — Monthly recovery rate %
│   ├── v_cases_monthly         — 12-month case trend
│   ├── v_analyst_performance   — Cases per analyst + score
│   ├── v_admin_analysts        — Analyst user list
│   ├── v_recent_login_history  — Latest auth events
│   └── v_assigned_tasks        — Task list joined with user names
│
├── Stored Procedures
│   ├── sp_insert_transaction   — Auto-IDs, risk + status classification
│   ├── sp_insert_case          — Auto-IDs, SLA assignment
│   ├── sp_insert_alert         — Auto-IDs, queue initialization
│   └── sp_assign_task          — Task creation + case analyst update
│
└── Triggers
    ├── trg_users_after_insert       — Audit on new user
    ├── trg_tasks_after_insert       — Audit on task creation
    └── trg_settings_after_update    — Audit on settings change
```
---

Project Structure
```
fraudshield/
├── fraud_dashboard.html   — Full frontend SPA (all views, charts, UI)
├── db.php                 — PHP backend API + self-initializing schema 
├── fraudshield_schema.sql — MySQL schema for manual import
└── README.md
```
> The backend is a single-file PHP API (`db.php`) that handles all routes via a `?action=` query parameter — no framework required. It also self-initializes the schema on first run.
---
Getting Started
> **The fastest way:** Use XAMPP (Windows/macOS/Linux). It gives you PHP + MySQL in one installer with zero configuration. The whole setup takes about 5 minutes.
---
## 🪟 Windows — Using XAMPP (Recommended)

1. **Download and install XAMPP** Go to [apachefriends.org](https://www.apachefriends.org) and download the Windows installer. Run it and install to the default path `C:\xampp`. During installation, make sure Apache and MySQL are checked.

2. **Start Apache and MySQL** Open the XAMPP Control Panel (search for it in your Start Menu) and click **Start** next to both Apache and MySQL. Both status lights should turn green.

3. **Put the project files in the right folder** Copy your project folder (containing `fraud_dashboard.html`, `db.php`, and `fraudshield_schema.sql`) into:  
   `C:\xampp\htdocs\fraudshield\`
   
Copy your project folder (containing `fraud_dashboard.html`, `db.php`, and `fraudshield_schema.sql`) into:
```
C:\xampp\htdocs\fraudshield\
```
If you're cloning from GitHub:
```bash
cd C:\xampp\htdocs
git clone https://github.com/YOUR_USERNAME/fraudshield.git
```
Step 4 — Open the app in your browser
```
http://localhost/fraudshield/fraud_dashboard.html
```
macOS — Using XAMPP
1.  Download XAMPP for macOS
Go to https://www.apachefriends.org, download the macOS `.dmg` installer, and drag it to your Applications folder.
2. Start the servers
Open XAMPP from Applications, and in the manager window click Start All (or start Apache and MySQL individually from the Manage Servers tab).
3. Copy project files
```bash
# Open terminal and navigate to XAMPP's web root
cd /Applications/XAMPP/xamppfiles/htdocs

# Clone or copy your project here
git clone https://github.com/YOUR_USERNAME/fraudshield.git
```
Step 4 — Open in browser
```
http://localhost/fraudshield/fraud_dashboard.html
```
> **macOS tip:** If Apache won't start because port 80 is in use, open XAMPP → Manage Servers → Apache → Configure and change the port to `8080`. Then use `http://localhost:8080/fraudshield/fraud_dashboard.html`.
---
🐧 Linux — Using XAMPP or Native Stack
Option A — XAMPP on Linux
```bash
# Download XAMPP installer from apachefriends.org, then:
chmod +x xampp-linux-x64-*.run
sudo ./xampp-linux-x64-*.run

# Start XAMPP
sudo /opt/lampp/lampp start

# Copy project files
sudo cp -r /path/to/fraudshield /opt/lampp/htdocs/
```
Open: `http://localhost/fraudshield/fraud_dashboard.html`
Option B — Native LAMP stack (Ubuntu/Debian)
```bash
# Install Apache, PHP, MySQL, and required PHP extensions
sudo apt update
sudo apt install apache2 php php-mysql mysql-server libapache2-mod-php -y

# Start services
sudo systemctl start apache2
sudo systemctl start mysql

# Secure MySQL and set a root password (optional but recommended)
sudo mysql_secure_installation

# Copy project to web root
sudo cp -r /path/to/fraudshield /var/www/html/fraudshield

# Fix permissions
sudo chown -R www-data:www-data /var/www/html/fraudshield
``
`
Open: `http://localhost/fraudshield/fraud_dashboard.html`

---
⚙️ DB Credentials (All Platforms)
Open `db.php` in any text editor and check lines 7–9. By default they match a fresh XAMPP install:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');        // XAMPP default is blank password
define('DB_NAME', 'fraudshield');
```
If your MySQL has a root password set, put it in `DB_PASS`. If you want to use a different database user, create one in phpMyAdmin first.

---
🗄️ phpMyAdmin (Optional — to view/inspect your database)
XAMPP includes phpMyAdmin for browsing your database visually:

```
http://localhost/phpmyadmin
```
Log in with `root` and your password (blank by default in XAMPP). You'll see the `fraudshield` database appear here after first page load.

---
| Role | Email | Password |
| :--- | :--- | :--- |
| **Admin** | `admin@fraudshield.io` | `admin123` |
| **Manager** | `manager@fraudshield.io` | `manager123` |
---
### How Fraud Scoring Works

FraudShield uses a rule-based risk score (0–99) to classify each transaction:

| Score Range | Risk Level | Transaction Status |
| :--- | :--- | :--- |
| 85 – 99 | Critical | Blocked |
| 61 – 84 | High | Flagged |
| 31 – 60 | Medium | Under Review |
| 0 – 30 | Low | Cleared |

The score is currently assigned at insertion time (manually or via demo seed data). Future versions will compute it dynamically from behavioral signals — see the **Roadmap**.

---

### Fraud Rule Engine

The fraud rule engine (in the Admin panel) stores named rules with configurable thresholds, such as:

* **High-Value Transaction** — flag any single transaction above a set amount

* **Velocity Check** — multiple transactions in a short window

* **Geographic Anomaly** — transaction from an unusual location

* **Device Mismatch** — transaction from an unrecognized device fingerprint

---
### Real-World References & Inspiration

This project was built by researching how production fraud detection systems actually operate. Below are the key resources that shaped the design and logic.

1. **ACFE — Report to the Nations 2024:**
[https://acfepublic.s3.us-west-2.amazonaws.com/2022+Report+to+the+Nations.pdf](https://acfepublic.s3.us-west-2.amazonaws.com/2022+Report+to+the+Nations.pdf)

2. **IEEE Xplore:**
[https://ieeexplore.ieee.org/document/11058990](https://ieeexplore.ieee.org/document/11058990)

3. **Stripe Radar:**
[https://stripe.com/radar/fraud-teams](https://stripe.com/radar/fraud-teams)

4. **PayPal Fraud & Risk Management:**
[https://www.paypal.com/us/brc/operations/fraud-management](https://www.paypal.com/us/brc/operations/fraud-management)

5. **The $81 Million Bangladesh Bank Heist (SWIFT Fraud):**
[https://thehedgefundjournal.com/the-bangladesh-cyberheist/](https://thehedgefundjournal.com/the-bangladesh-cyberheist/)

6. **Account Takeover Fraud — FBI IC3 Report:**
[https://www.fbi.gov/investigate/cyber/alerts/2025/account-takeover-fraud-via-impersonation-of-financial-institution-support](https://www.fbi.gov/investigate/cyber/alerts/2025/account-takeover-fraud-via-impersonation-of-financial-institution-support)

7. **Kaggle — IEEE-CIS Fraud Detection:**
[https://www.kaggle.com/datasets?search=fraud+detection+in+transactions](https://www.kaggle.com/datasets?search=fraud+detection+in+transactions)
---

### Fraud Techniques Researched

The fraud types tracked in FraudShield's case management module are based on research into real techniques:

| Fraud Type | Description | Key Reference |
| :--- | :--- | :--- |
| Card Not Present (CNP) | Using stolen card details online without the physical card | EMVCo CNP Fraud |
| Account Takeover (ATO) | Credential stuffing or phishing to hijack a legitimate account | CISA Account Takeover Guide |
| Synthetic Identity | Combining real and fake PII to create a fraudulent identity | NY Fed Synthetic Identity Paper |
| P2P / Authorized Push Payment (APP) | Victim tricked into authorizing a transfer to a fraudster | PSR APP Fraud Data UK |
| Wire Transfer Fraud | Fraudulent SWIFT/wire instructions, often via BEC | FBI BEC Wire Fraud |
| Money Muling | Using third-party accounts to move stolen funds | Europol Money Mule Campaign |
| First-Party Fraud | Genuine customer intentionally defaults or misrepresents | Experian First-Party Fraud |
| Bust-Out Fraud | Building up credit then maxing it out with no intent to repay | ACFE Fraud Examiners Manual |

---
### Roadmap — Future Enhancements

This is where the project gets really interesting as We continue studying fraud detection, machine learning, and financial crime. Planned additions:

#### 🤖 Machine Learning Integration
- [ ] **Real-time fraud scoring model** — Train an XGBoost or LightGBM classifier on the Kaggle IEEE-CIS dataset and expose it as a Python Flask microservice that `db.php` calls for each new transaction.  
  *Reference: XGBoost for Fraud Detection — Towards Data Science*
- [ ] **Anomaly detection with Isolation Forest** — Flag behavioral outliers (unusual time, location, or amount) without needing labeled fraud data.  
  *Reference: Isolation Forest Paper — Liu et al.*
- [ ] **Graph-based fraud detection** — Model customer-merchant-device relationships as a graph; fraud rings show unusual clustering patterns.  
  *Reference: Graph Neural Networks for Fraud — Amazon*
- [ ] **Velocity feature engineering** — Compute rolling windows (5-min, 1-hour, 24-hour) of transaction count and amount per customer/device/IP as model features.
- [ ] **Explainability layer (SHAP)** — Show analysts why a transaction was flagged, not just the score.  
  *Reference: SHAP for ML Explainability*

#### 🔍 Detection Techniques To Add
- [ ] **Device fingerprinting** — Track canvas fingerprint, browser plugins, screen resolution to identify device reuse across different customer IDs.  
  *Reference: FingerprintJS*
- [ ] **Behavioral biometrics** — Typing cadence, mouse movement patterns, and touch pressure as passive authentication signals.  
  *Reference: NeuroID Behavioral Biometrics*
- [ ] **Geolocation velocity** — Flag physically impossible travel (transaction in Karachi, then London 10 minutes later).
- [ ] **Dark web monitoring** — Check if a customer's email/card appears in known breach datasets.  
  *Reference: HaveIBeenPwned API*
- [ ] **Network analysis for money mule detection** — Graph visualization of fund flows between accounts.

#### 🏗️ Platform Improvements
- [ ] **Real-time alerts via WebSockets** — Push new high-risk transactions to the analyst dashboard without page refresh.
- [ ] **Email/SMS notifications** — Alert analysts when a Critical case is assigned or an SLA is about to breach.
- [ ] **REST API with JWT authentication** — Replace session-based auth with token-based API security.
- [ ] **Role-based access control (RBAC)** — Fine-grained permissions per module per role.
- [ ] **Export to CSV / PDF** — Case and transaction report generation.
- [ ] **Audit trail visualizer** — Timeline view of every action taken on a case.
- [ ] **Multi-tenancy** — Support multiple banks/clients within one deployment.

#### 📊 Analytics & Reporting
- [ ] **Network graph visualization** — D3.js force-directed graph showing customer–account–device connections.
- [ ] **Cohort analysis** — Track fraud rates by customer acquisition channel or account age.
- [ ] **Model performance dashboard** — Precision, recall, F1 score, AUC-ROC for the ML model, tracked over time.
- [ ] **Regulatory reporting templates** — SAR (Suspicious Activity Report) generation in standard format.

#### 🔗 Integrations To Study
- [ ] **SWIFT gpi** — Understanding real interbank wire fraud and how SWIFT's global payment initiative monitors it.  
  *Reference: SWIFT gpi Documentation*
- [ ] **Open Banking / PSD2** — How Strong Customer Authentication (SCA) requirements in Europe affect fraud patterns.  
  *Reference: EBA PSD2 Guidelines*
- [ ] **OSINT APIs** — Integrate IP reputation (AbuseIPDB), email age (EmailAge), and phone carrier lookup into scoring.
---

#### License
This project is licensed under the MIT License. Free to use for learning, portfolio, and non-commercial projects.

---
<div align="center">

---

### 👥 Contributors

This project was developed and maintained by:

**Khadija Sohail** &bull; **Manal Imran** &bull; **Fatima Kamran** &bull; **Mahnoor Mazhar**

*FraudShield — Advanced Risk Management & Transaction Monitoring*

</div>

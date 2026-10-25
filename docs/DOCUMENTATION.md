# Docvera v2.0.0 — Operator, Deployment & REST API Documentation

**Project:** Docvera — Privacy-First, Self-Hosted Document Processing Platform & REST API  
**License:** Apache-2.0 (`LICENSE`)  
**Version:** `2.0.0`  
**Runtime:** PHP `8.2+` (`8.2` / `8.3` / `8.4`)

---

## Table of Contents

1. [Getting Started](#1-getting-started)
2. [Installation (Web Installer & CLI Installer)](#2-installation)
3. [Server Requirements & Realistic Hosting Compatibility](#3-server-requirements--hosting-compatibility)
4. [Optional Dependencies & Capability Matrix](#4-optional-dependencies--capability-matrix)
5. [Environment Configuration (`.env`)](#5-environment-configuration)
6. [Admin Setup & Control Plane](#6-admin-setup--control-plane)
7. [Business Modes (`saas`, `free_ads`, `private`)](#7-business-modes)
8. [Users & Role Management](#8-users--role-management)
9. [Plans & Quota Engine](#9-plans--quota-engine)
10. [Payments & Webhook Security (Stripe & PayPal)](#10-payments--webhook-security)
11. [Tool Configuration & Entitlement Overrides](#11-tool-configuration--entitlement-overrides)
12. [File Retention & Privacy Architecture](#12-file-retention--privacy-architecture)
13. [Queue & Asynchronous Worker (`bin/worker`)](#13-queue--asynchronous-worker)
14. [Scheduler & Cron Cleanup (`bin/cleanup`)](#14-scheduler--cron-cleanup)
15. [REST API (`/api/v1/*`)](#15-rest-api)
16. [API Keys & Scopes](#16-api-keys--scopes)
17. [SEO, Sitemap & Structured Data](#17-seo-sitemap--structured-data)
18. [Branding & Advertisement Slots](#18-branding--advertisement-slots)
19. [Backup Procedure](#19-backup-procedure)
20. [Upgrade & Rollback Procedure](#20-upgrade--rollback-procedure)
21. [Troubleshooting](#21-troubleshooting)
22. [Security Recommendations](#22-security-recommendations)
23. [Frequently Asked Questions (FAQ)](#23-frequently-asked-questions-faq)
24. [Changelog Summary](#24-changelog-summary)

---

## 1. Getting Started

**Docvera** is a self-hosted PDF and document processing workbench, multi-tenant SaaS application, and REST API server written in clean, zero-dependency PHP 8.2+.

Unlike wrapper scripts that secretly upload customer documents to paid third-party cloud APIs, Docvera executes every document transformation **locally on your server** inside isolated `0700` per-job working directories (`storage/app/jobs/job_*`).

### Key Capabilities
- **22 Document Tools across 5 Functional Categories:**
  - **18 Core PDF, Image & Text Tools:** Merge PDF (`merge-pdf`), Split PDF (`split-pdf`), Extract Pages (`extract-pages`), Delete Pages (`delete-pages`), Reorder Pages (`reorder-pages`), Rotate Pages (`rotate-pages`), Images to PDF (`images-to-pdf`), PDF to JPG / PNG (`pdf-to-images`), Compress PDF (`compress-pdf`), Protect PDF (`protect-pdf`), Unlock PDF (`unlock-pdf`), Watermark PDF (`watermark-pdf`), Crop PDF (`crop-pdf`), Add Page Numbers (`page-numbers`), Edit PDF Metadata (`pdf-metadata`), Grayscale PDF (`grayscale-pdf`), Flatten PDF (`flatten-pdf`), and Text / Markdown to PDF (`text-to-pdf`).
  - **4 Optional Capability-Gated Tools:** Word to PDF (`word-to-pdf`), Excel to PDF (`excel-to-pdf`), PowerPoint to PDF (`powerpoint-to-pdf` — enabled automatically when `libreoffice` is installed), and OCR PDF (`ocr-pdf` — enabled automatically when `tesseract` is installed).
- **3 Operating Modes:** Switchable at runtime via `/admin/overview` between **Freemium / Paid SaaS (`saas`)**, **Free / Ad-Supported Utility (`free_ads`)**, and **Private Internal Workspace (`private`)**.
- **3 Persistence Drivers:** Works out-of-the-box with the built-in concurrency-safe `file` JSON store (zero database daemon required), `sqlite` (PDO SQLite WAL mode), or `mysql` (PDO MySQL/MariaDB/PostgreSQL).

---

## 2. Installation

You can install Docvera using Docker Compose, git clone, or a release archive (`docvera-v2.0.0.zip`), and configure it via either the **Guided Web Installer (`/install`)** or the **CLI Installer (`php bin/install`)**.

### Step 1: Clone or Extract the Release Package
Clone the repository or extract `docvera-v2.0.0.zip` onto your server (for example `/var/www/docvera`):

```bash
git clone https://github.com/hoanle0126/Docvera.git /var/www/docvera
cd /var/www/docvera
chmod -R 0700 storage
chown -R www-data:www-data storage
```

### Step 2: Point Your Web Server DocumentRoot to `public/`
Configure Nginx or Apache so the public web root is `/var/www/docvera/public`.  
*(If your hosting panel forces the project root as DocumentRoot, the included root `.htaccess` automatically blocks direct HTTP access to `config/`, `src/`, `storage/`, `database/`, `bin/`, and `.env` while forwarding traffic to `public/`.)*

#### Nginx Example (`/etc/nginx/sites-available/docvera.conf`)
```nginx
server {
    listen 80;
    server_name docs.example.com;
    root /var/www/docvera/public;
    index index.php;

    client_max_body_size 100M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 120s;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### Step 3A: Guided Web Installer (Browser)
1. Open your browser and navigate to `https://your-domain.com/install` (visiting `/` on an unconfigured installation without `.env` redirects automatically to `/install`).
2. Review Section 1 (**Required Server Prerequisites**) and Section 2 (**Optional Document Processing Binaries**).
   - Missing optional binaries (such as `tesseract` or `libreoffice`) **never block installation**; those specific tools simply remain marked unavailable until you install the binary.
3. Select your **Business Mode** (`saas`, `free_ads`, or `private`), **Database Driver** (`file`, `sqlite`, or `mysql`), and enter your **Administrator Name, Email, and Password**.
4. Click **Complete Installation & Lock Installer**.
5. Docvera generates a 256-bit CSPRNG `APP_SECRET`, writes `.env` (mode `0600`), runs all database migrations, creates your Administrator account, writes `storage/app/installed.lock`, and signs you into `/admin`.
6. Once `storage/app/installed.lock` is written, `/install` is permanently locked and returns `HTTP 403 Forbidden` on any subsequent web request.

### Step 3B: CLI Installer (SSH / Automated Deployment)
```bash
# Run pre-flight environment readiness check only:
php bin/install --check-only

# Execute full non-interactive installation:
php bin/install \
  --site-name="Docvera Workbench" \
  --app-url="https://docs.example.com" \
  --mode="saas" \
  --db-driver="file" \
  --admin-name="System Admin" \
  --admin-email="admin@example.com" \
  --admin-password="YourStrongPassword123!"
```

---

## 3. Server Requirements & Hosting Compatibility

### Realistic Hosting Compatibility Disclosure
Docvera executes real binary PDF/document engines (`ghostscript`, `poppler`, `qpdf`, `libreoffice`, `tesseract`) via PHP `proc_open()`.

| Hosting Tier | Compatibility Level | Supported Features & Limitations |
| :--- | :--- | :--- |
| **Linux VPS / Cloud Instance / Dedicated Server** *(Ubuntu 22.04/24.04, Debian 12, AlmaLinux 9, Docker)* | **Recommended (100% Full Capability)** | All 22 tools supported. Full access to `apt`/`dnf` to install Ghostscript, Poppler, QPDF, LibreOffice, and Tesseract, plus Supervisor/cron for background workers. |
| **Managed PaaS / Cloud Containers** *(Render, Railway, Fly.io, DigitalOcean App Platform, Coolify, Herd)* | **Full Capability** | All 18–22 tools supported depending on container packages. |
| **Restrictive CPanel Shared Hosting** *(where `proc_open()` is disabled or CLI binaries are absent)* | **Limited (Native PHP Subset Only)** | If a shared host disables `proc_open()` or lacks `gs`/`poppler`, only pure-PHP tools (**Images to PDF** and **Text to PDF**) can execute. **We recommend a VPS ($4–$6/mo) rather than generic shared hosting** if you want all 18–22 PDF, Office, and OCR tools active. |

### Minimum vs. Recommended Server Specs

| Resource | Minimum Environment (Core 18 Tools) | Recommended Production Environment (All 22 Tools + OCR/Office) |
| :--- | :--- | :--- |
| **OS** | Linux (Ubuntu 20.04+, Debian 11+, RHEL/Rocky 8+) or macOS | Ubuntu 22.04 LTS / 24.04 LTS or Debian 12 |
| **CPU / RAM** | 1 vCPU, 1 GB RAM | 2+ vCPU, 4 GB RAM (LibreOffice & Tesseract benefit from >= 2 GB RAM) |
| **PHP Version** | PHP `8.2.0` (`cli` + `fpm`) | PHP `8.3+` (`cli` + `fpm`) |
| **Required PHP Extensions** | `fileinfo`, `openssl`, `zip`, `mbstring`, `json` | `fileinfo`, `openssl`, `zip`, `mbstring`, `json`, `gd`, `pdo_sqlite` / `pdo_mysql` |
| **PHP `proc_open`** | Enabled in `php.ini` (`disable_functions` must not block `proc_open`) | Enabled |
| **PHP Memory & Upload Limits** | `memory_limit = 256M`, `upload_max_filesize = 50M`, `post_max_size = 64M` | `memory_limit = 512M`, `upload_max_filesize = 100M`, `post_max_size = 128M` |
| **Writable Paths** | `storage/app/jobs`, `storage/app/staged`, `storage/app/database`, `storage/logs` (`0700`) | Same (`0700`, owned by web server user) |

---

## 4. Optional Dependencies & Capability Matrix

Run `php bin/capabilities` at any time (or visit `/system/capabilities` or `/admin/health`) to inspect which binaries are installed on your host.

### Installing All Processing Engines on Ubuntu / Debian
```bash
sudo apt update
sudo apt install -y \
  ghostscript \
  poppler-utils \
  qpdf \
  libreoffice-core libreoffice-writer libreoffice-calc libreoffice-impress \
  tesseract-ocr tesseract-ocr-eng
```

### Tool-to-Binary Dependency Mapping

| Binary Package | Binaries Checked | Required or Optional? | Tools Enabled / Accelerated | Behavior When Absent |
| :--- | :--- | :--- | :--- | :--- |
| **Ghostscript** (`ghostscript`) | `gs` | **Required for PDF Manipulation** (Classified as Optional during `/install`) | `merge-pdf`, `split-pdf`, `extract-pages`, `delete-pages`, `reorder-pages`, `rotate-pages`, `compress-pdf`, `protect-pdf`, `unlock-pdf`, `watermark-pdf`, `add-page-numbers`, `crop-pdf`, `pdf-metadata`, `grayscale-pdf`, `flatten-pdf`, `pdf-to-jpg`, `pdf-to-png` | PDF manipulation tools display a truthful *"Server Capability Unavailable"* badge with installation instructions. Native `images-to-pdf` and `text-to-pdf` continue working. |
| **Poppler** (`poppler-utils`) | `pdfinfo`, `pdftoppm`, `pdftotext`, `pdfseparate`, `pdfunite` | **Recommended** | `pdf-to-text`, `pdf-to-jpg`, `pdf-to-png`, fast page-count/encryption inspection, and fast `split-pdf` / `merge-pdf` | `pdf-to-text` is disabled; PDF rendering and page inspection automatically fall back to Ghostscript (`gs`). |
| **QPDF** (`qpdf`) | `qpdf` | **Optional Accelerator** | Lossless structural `merge-pdf`, `rotate-pages`, and 256-bit AES `protect-pdf` / `unlock-pdf` | Automatically falls back to `GhostscriptDriver` (`gs`) with zero loss of tool availability. |
| **LibreOffice** (`libreoffice`) | `soffice` / `libreoffice` | **Optional Capability** | `word-to-pdf` (`.doc`, `.docx`), `excel-to-pdf` (`.xls`, `.xlsx`), `powerpoint-to-pdf` (`.ppt`, `.pptx`) | The 3 Office-to-PDF tools are gracefully marked *Unavailable* in the UI and return HTTP `422` on `/api/v1` with clear remediation text. |
| **Tesseract OCR** (`tesseract-ocr`) | `tesseract` | **Optional Capability** | `ocr-pdf` (OCR Scanned PDF & Images to Searchable Text) | The OCR tool is gracefully marked *Unavailable* without affecting the other 21 tools. |

---

## 5. Environment Configuration

Docvera reads configuration from `.env` in the project root via `config/app.php`. Below is the complete reference of supported `.env` keys:

| Key | Default | Description |
| :--- | :--- | :--- |
| `APP_NAME` | `"Docvera"` | Default brand/workspace title (can be overridden in `/admin/branding`). |
| `APP_ENV` | `"production"` | Environment name (`production`, `staging`, `local`). |
| `APP_DEBUG` | `"false"` | **Keep `false` in production.** Never expose stack traces to end users. |
| `APP_URL` | `"http://localhost:8080"` | Canonical base URL used for sitemaps, canonical links, and billing return URLs. |
| `APP_TIMEZONE` | `"UTC"` | Server PHP timezone identifier. |
| `APP_INSTALLED` | `"false"` | Set to `"true"` automatically by the installer alongside `storage/app/installed.lock`. |
| `APP_SECRET` | *(Generated 64-char hex)* | 256-bit secret key used for HMAC-SHA256 job tokens and API key hashing. |
| `DB_CONNECTION` / `DB_DRIVER` | `"file"` | Database persistence engine: `file` (atomic JSON store), `sqlite`, or `mysql`. |
| `DB_SQLITE_PATH` | `"storage/app/database/docvera.sqlite"` | Relative or absolute path to the SQLite database file when `DB_CONNECTION=sqlite`. |
| `DB_DSN` | `""` | Optional full PDO DSN override for MySQL/PostgreSQL (`mysql:host=127.0.0.1;port=3306;dbname=docvera;charset=utf8mb4`). |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` | `127.0.0.1` / `3306` / `docvera` | MySQL connection parameters when `DB_CONNECTION=mysql` and `DB_DSN` is empty. |
| `DB_USERNAME` / `DB_PASSWORD` | `root` / `""` | Database credentials for `mysql`/`pgsql`. |
| `MAX_FILE_SIZE_MB` | `"50"` | System-wide default upload size ceiling in megabytes (plans can override per tier). |
| `MAX_FILES_PER_JOB` | `"20"` | System-wide default maximum files per batch operation. |
| `PROCESS_TIMEOUT_SECONDS` | `"60"` | Hard wall-clock timeout in seconds for each external CLI subprocess (`proc_open`). |
| `RETENTION_TTL_MINUTES` | `"60"` | Default minutes before generated output files are permanently purged. |
| `QUEUE_DRIVER` | `"sync"` | Execution mode (`sync` by default; `async=1` API jobs use the DB queue + `bin/worker`). |
| `SCHEDULER_ENABLED` | `"true"` | Marks the TTL cleanup scheduler capability active. |
| `BIN_GS`, `BIN_QPDF`, `BIN_PDFINFO`, `BIN_PDFTOPPM`, `BIN_PDFTOTEXT`, `BIN_PDFSEPARATE`, `BIN_PDFUNITE`, `BIN_LIBREOFFICE`, `BIN_TESSERACT` | `""` (Auto-detect) | Optional absolute paths to binaries (e.g. `/usr/local/bin/gs`) if installed outside standard `PATH`. Set to `__DISABLED__` to forcibly disable a binary. |

---

## 6. Admin Setup & Control Plane

The **Docvera Administration Console** is located at `/admin` and is strictly protected by `AdminController::assertAdmin()` (returning HTTP `403` to guests and standard users).

### How the First Administrator is Created
1. **Via Web or CLI Installer (`/install` or `php bin/install`):** Creates your primary Administrator account (`role = 'admin'`, `plan_id = 'business'`) and locks the installer.
2. **First-User Bootstrap Rule:** If you configure `.env` manually without running `/install`, the **very first user account registered** on an empty database is automatically promoted to `role = 'admin'`.

### All 16 Admin Console Sections (`/admin/{section}`)
- `/admin/overview` — Multi-Deployment Business Mode switcher & live platform snapshot.
- `/admin/tools` — Enable/disable individual tools, toggle guest/API eligibility, and override max files/MB/timeout.
- `/admin/users` — Provision new users/admins, change user roles, assign plan tiers, or suspend accounts.
- `/admin/plans` — Edit quotas, pricing, retention windows, concurrency slots, and feature flags for `guest`, `free`, `pro`, and `business`.
- `/admin/subscriptions` — Inspect active, trialing, and canceled customer subscriptions.
- `/admin/payments` — Inspect recorded payment transactions and the idempotent `webhook_events` ledger.
- `/admin/usage` — Monitor real-time daily/monthly usage buckets across users and guest sessions.
- `/admin/jobs` — Audit processing job history, drivers used, input/output sizes, and expiration timestamps.
- `/admin/health` — Truthful binary & runtime diagnostics (`CapabilityDetector`).
- `/admin/storage` — Live disk telemetry (`storage/app/jobs`, `storage/app/staged`, `storage/logs`), one-click **Run Expired Workspace Purge Now**, and retention policy settings.
- `/admin/api-usage` — Inspect provisioned API keys across all users and review `/api/v1` request audit logs.
- `/admin/seo` — Global indexing/sitemap toggles and per-tool SEO title, description, canonical URL, and sitemap flags.
- `/admin/branding` — Customize workspace name, tagline, hex accent color, footer compliance notice, and HTML ad slots.
- `/admin/pages` — Built-in CMS editor for `/p/privacy`, `/p/terms`, and `/p/security`.
- `/admin/security` — Configure Stripe & PayPal credentials (write-only secrets), registration policy (`open` vs `closed`), guest tool access, and global REST API toggle.
- `/admin/updates` — Inspect codebase vs schema version, run pending migrations (`001`, `002`), clear staged caches, and view the safe upgrade checklist.

---

## 7. Business Modes

In `/admin/overview`, you can switch Docvera between three commercial operating models at any time without modifying code:

1. **Freemium / Paid SaaS (`saas` — Default):**
   - Unauthenticated guests can run guest-eligible tools under the `guest` plan quota.
   - Public registration (`/register`) and `/pricing` are open.
   - Users can upgrade to paid plans (`pro`, `business`) via Stripe or PayPal to unlock higher daily/monthly quotas, larger file sizes, extended file retention, and `/api/v1` REST API access.
2. **Free / Ad-Supported Utility (`free_ads`):**
   - Guest tools are enabled, commercial billing checkouts are hidden, and configurable HTML ad slots (`ad_slot_header`, `ad_slot_tool_sidebar`, `ad_slot_result`) render for plans where `ads_visible` is enabled.
3. **Private Internal Workspace (`private`):**
   - All unauthenticated requests to `/`, `/tools/*`, and `/router/inspect` redirect immediately to `/login?notice=private_workspace` (or return HTTP `401` JSON).
   - Self-service registration is closed; only Administrators can provision team accounts in `/admin/users`.
   - Search engine indexing (`robots.txt` and `noindex`) and `/sitemap.xml` are automatically disabled.

---

## 8. Users & Role Management

Docvera supports three principal types resolved by `Docvera\Auth\AuthManager`:
- **Guest (`guest`):** Identified by a cryptographic session hash (`guest:<sha256_prefix>`). Governed by the `guest` plan.
- **Registered User (`user`):** Authenticated account governed by its assigned plan (`free`, `pro`, `business`) or active subscription.
- **Administrator (`admin`):** Full access to `/admin/*` and all enabled tools.

### Account Security Controls
- **Password Hashing:** `password_hash($password, PASSWORD_DEFAULT)` (bcrypt/Argon2id).
- **Brute-Force Rate Limiting:** `/login` locks out an IP + email pair with HTTP `429` after 10 failed sign-in attempts within a 15-minute window. `/register` limits an IP address to 10 registrations per 15-minute window.
- **Session Fixation Protection:** Both `session_regenerate_id(true)` and `CsrfGuard::rotateToken()` execute on every login and logout.

---

## 9. Plans & Quota Engine

Docvera seeds four configurable plans (`guest`, `free`, `pro`, `business`) in `PlatformRepository` and enforces them atomically via `EntitlementService`:

| Entitlement Key | `guest` | `free` (Starter) | `pro` (Workbench Pro) | `business` (Enterprise) |
| :--- | :--- | :--- | :--- | :--- |
| **Monthly / Yearly Price** | $0 | $0 | $19/mo ($180/yr) | $49/mo ($468/yr) |
| **`ops_per_day`** | 50 | 25 | 500 | 5,000 |
| **`ops_per_month`** | 500 | 300 | 10,000 | 100,000 |
| **`max_file_size_mb`** | 50 MB | 50 MB | 100 MB | 250 MB |
| **`max_files_per_op`** | 20 | 20 | 50 | 100 |
| **`max_pages_per_op`** | 200 | 250 | 1,000 | 5,000 |
| **`concurrent_jobs`** | 2 | 2 | 5 | 15 |
| **`ocr_allowed`** | Yes | Yes | Yes | Yes |
| **`batch_allowed`** | Yes | Yes | Yes | Yes |
| **`api_access`** | No | No | **Yes** | **Yes** |
| **`api_requests_per_day`** | 0 | 0 | 1,000 | 10,000 |
| **`api_rate_limit_per_min`** | 10 | 20 | 60 | 240 |
| **`retention_minutes`** | 30 min | 60 min | 180 min | 720 min |
| **`ads_visible`** | Yes | Yes | No | No |

Every parameter above can be customized live in `/admin/plans`.

---

## 10. Payments & Webhook Security

Docvera includes a modular payment architecture (`BillingService`, `StripeProvider`, `PayPalProvider`) supporting both **Sandbox/Test** and **Live** modes.

### Configuring Stripe or PayPal
1. Navigate to `/admin/security` (or set keys in `.env`).
2. Enable **Stripe** or **PayPal**, select `test`/`sandbox` or `live`, and enter your API keys and Webhook Secret.
3. Configure your provider webhook URLs:
   - **Stripe Webhook Endpoint:** `POST https://your-domain.com/webhooks/billing/stripe`
     - Required header: `Stripe-Signature: t=<timestamp>,v1=<hmac_sha256>`
     - Handled events: `checkout.session.completed`, `invoice.paid`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.payment_failed`.
   - **PayPal Webhook Endpoint:** `POST https://your-domain.com/webhooks/billing/paypal`
     - Required header: `HTTP_PAYPAL_TRANSMISSION_SIG` (HMAC-SHA256 verified against `paypal_webhook_secret`).
     - Handled events: `BILLING.SUBSCRIPTION.ACTIVATED`, `PAYMENT.SALE.COMPLETED`, `BILLING.SUBSCRIPTION.CANCELLED`, `BILLING.SUBSCRIPTION.EXPIRED`.

### Webhook Security Guarantees
- **Cryptographic Signature Verification:** Unsigned or tampered payloads are rejected with HTTP `400`.
- **Replay Window Enforcement:** Stripe timestamps older than 300 seconds are rejected.
- **Idempotency Ledger (`webhook_events`):** Every processed `provider + event_id` pair is recorded in `webhook_events`. Duplicate webhook deliveries return `200 OK` (`status: duplicate_ignored`) without double-crediting payments or mutating state twice.

---

## 11. Tool Configuration & Entitlement Overrides

In `/admin/tools`, administrators can customize any of the 22 registered tools without touching code:
- **Enabled (`is_enabled`):** Hides the tool from the public directory and blocks execution when unchecked.
- **Guest Allowed (`guest_allowed`):** Requires users to sign in before using the tool when unchecked.
- **REST API Enabled (`api_enabled`):** Controls whether the tool can be invoked via `/api/v1/*`.
- **Max Files / Max MB / Timeout Overrides:** Override per-tool file counts, upload size limits, and execution timeouts.

---

## 12. File Retention & Privacy Architecture

Docvera is engineered for high-trust document privacy:
1. **Isolated `0700` Workspaces:** Each job creates `storage/app/jobs/job_<32hex>/{input,work,output}` with `0700` permissions.
2. **Immediate Input & Scratch Wipe:** As soon as a tool finishes processing, `TempStorageManager::purgeDirectoryContents()` immediately deletes all uploaded source files (`input/`) and intermediate work files (`work/`) inside a `finally` block—even if processing fails.
3. **Cryptographic Download Authorization:** Output files are stored outside `public/` and can only be downloaded through `/jobs/{id}/download` by the owning user, owning session hash, or holder of the HMAC-SHA256 `download_token`.
4. **User-Initiated "Delete File Now":** Users can click **Delete File Now** on any result page or dashboard row (or call `DELETE /api/v1/jobs/{id}`) to wipe the output file immediately while preserving safe, non-sensitive operation telemetry in their job history.

---

## 13. Queue & Asynchronous Worker

Docvera supports both **synchronous execution** (default for browser tools and standard API calls) and **asynchronous queued execution** (for `/api/v1/*` requests that pass `async=1`).

### How Asynchronous Jobs Work
1. A client sends `POST /api/v1/pdf/compress` with `async=1`.
2. `ToolExecutionService::enqueueAsync()` validates the uploaded files, reserves a concurrency slot (`active_concurrent_jobs`) and daily/monthly quota, stages the input files into `storage/app/jobs/job_<id>/input`, and returns HTTP `202 Accepted` with `"status": "queued"` and a status polling URL (`/api/v1/jobs/{id}`).
3. The CLI worker (`bin/worker`) picks up queued jobs, executes the driver, transitions the job to `completed` (or `failed`), Purges `input/` and `work/`, and releases the concurrency slot in its `finally` block.

### Running the Queue Worker
```bash
# Process up to 25 queued jobs and exit (ideal for every-minute cron):
php bin/worker --once --limit=25

# Run continuously as a background daemon (ideal for systemd / Supervisor):
php bin/worker --daemon --sleep=2 --limit=10
```

You can also trigger a queue worker sweep manually from `/admin` (`run_queue_worker`).

---

## 14. Scheduler & Cron Cleanup

Expired job workspaces (`expires_at <= now`) and abandoned Smart Router staging directories (`storage/app/staged/stg_*` older than 30 minutes) are cleaned up by `CleanupService::purgeExpired()`.

### Recommended Cron Entry
Add the following cron entry (via `crontab -e`) to run deterministic cleanup every 10 minutes:
```cron
*/10 * * * * /usr/bin/php /var/www/docvera/bin/cleanup >> /var/www/docvera/storage/logs/cron-cleanup.log 2>&1
```

### Automatic Opportunistic Fallback (When Cron is Unavailable)
If your hosting environment does not provide cron access, Docvera automatically runs a non-blocking opportunistic cleanup sweep on ~5% of incoming web requests (`Controller::handle()`), plus provides a manual **Run Expired Workspace Purge Now** button in `/admin/storage`.

---

## 15. REST API

Docvera exposes a versioned REST API under `/api/v1/*`. Live interactive documentation is served at `/developers/api` and the machine-readable OpenAPI 3.1.0 specification is served at `GET /api/v1/openapi.json`.

### Core Endpoints
| Method & Endpoint | Scope Required | Description |
| :--- | :--- | :--- |
| `GET /healthz` | *Public* | Lightweight JSON health check (`200 OK` or `503 Service Unavailable`). |
| `GET /api/v1/health` | *Public* | Returns truthful capability readiness (`ok: true` or `503` if required core checks fail). |
| `GET /api/v1/capabilities` | *Public* | Returns complete host capability and 22-tool readiness matrix. |
| `GET /api/v1/openapi.json` | *Public* | OpenAPI 3.1.0 specification generated dynamically from `ToolRegistry`. |
| `GET /api/v1/tools` | *Public* | Lists all enabled API-eligible tools, active drivers, and option schemas. |
| `GET /api/v1/usage` (or `/api/v1/account/usage`) | *Authenticated* | Returns current daily/monthly operation and API request usage counters and plan limits. |
| `GET /api/v1/jobs` | `jobs:read` | Lists recent processing jobs for the authenticated account (`?limit=20`). |
| `POST /api/v1/tools/{slug}` | `tools:execute` | Executes any enabled tool by slug (`multipart/form-data` with `files[]` and tool options). |
| `POST /api/v1/pdf/merge` | `tools:execute` | Semantic alias for `merge-pdf`. |
| `POST /api/v1/pdf/split` | `tools:execute` | Semantic alias for `split-pdf`. |
| `POST /api/v1/pdf/compress` | `tools:execute` | Semantic alias for `compress-pdf`. |
| `POST /api/v1/pdf/protect` | `tools:execute` | Semantic alias for `protect-pdf`. |
| `POST /api/v1/pdf/unlock` | `tools:execute` | Semantic alias for `unlock-pdf`. |
| `POST /api/v1/pdf/watermark` | `tools:execute` | Semantic alias for `watermark-pdf`. |
| `POST /api/v1/pdf/ocr` | `tools:execute` | Semantic alias for `ocr-pdf`. |
| `GET /api/v1/jobs/{jobId}` | `jobs:read` | Polls job status (`queued`, `processing`, `completed`, `failed`, `deleted_by_user`) and summary. |
| `GET /api/v1/jobs/{jobId}/download` | `jobs:download` | Streams the processed output binary (`application/pdf`, `application/zip`, `image/png`, etc.). |
| `DELETE /api/v1/jobs/{jobId}` | `jobs:delete` | Immediately wipes all physical files for the job (`status: deleted_by_user`). |

### Example `curl` Request
```bash
curl -X POST "https://docs.example.com/api/v1/pdf/compress" \
  -H "Authorization: Bearer dcv_live_your_secret_key" \
  -H "Idempotency-Key: req-2026-0001" \
  -F "files[]=@/path/to/report.pdf" \
  -F "compression_level=ebook"
```

---

## 16. API Keys & Scopes

Users on plans with `api_access = true` (such as `pro` and `business`, or administrators) can manage API keys in `/dashboard`:
- **Format:** `dcv_live_<48_hex_chars>`.
- **One-Time Secret Display:** The plain API key secret is shown **only once** upon creation or rotation. Only the SHA-256 HMAC hash (`key_hash`) and 15-character prefix (`key_prefix`) are stored in the database.
- **Granular Scopes:**
  - `tools:execute` — Submit document processing jobs.
  - `jobs:read` — Inspect tool directory, account usage, and job status.
  - `jobs:download` — Download completed output files.
  - `jobs:delete` — Trigger immediate file deletion.
- **Rotation & Revocation:** Clicking **Rotate** atomically revokes the old secret and issues a replacement key with identical scopes. Clicking **Revoke** permanently disables the key (`HTTP 401`).

---

## 17. SEO, Sitemap & Structured Data

Docvera includes built-in technical SEO controls in `/admin/seo`:
- **Dynamic `/sitemap.xml`:** Lists `/`, all enabled tools with `seo_indexable` and `seo_in_sitemap` enabled, and all published CMS pages (`/p/privacy`, `/p/terms`, `/p/security`). Automatically returns an empty `<urlset>` when `deployment_mode` is `private`.
- **Dynamic `/robots.txt`:** Allows public tool indexing while disallowing `/admin`, `/dashboard`, `/jobs/`, and `/api/`, and links to `Sitemap: <APP_URL>/sitemap.xml`.
- **Per-Tool Metadata & JSON-LD:** Every `/tools/{slug}` page renders a `<link rel="canonical">`, custom meta description, and Schema.org `WebApplication` JSON-LD structured data.

---

## 18. Branding & Advertisement Slots

In `/admin/branding`, you can customize:
- **Site / Brand Name & Header Tagline**
- **Accent Color (Hex):** Default `#c23b22` (Swiss Archival Vermilion). Injected safely as a CSS custom property (`--accent-vermilion`).
- **Footer Compliance Notice**
- **3 Configurable Ad Slots (`ad_slot_header`, `ad_slot_tool_sidebar`, `ad_slot_result`):** Render only when `ads_enabled` is active and the current user's plan has `ads_visible = true` (paid `pro` and `business` plans hide ads automatically).

---

## 19. Backup Procedure

Before upgrading or making infrastructure changes, back up your `.env` file and database store:

### If Using the Default `file` Store or `sqlite`
```bash
cd /var/www/docvera
cp .env .env.backup-$(date +%Y%m%d)
cp -r storage/app/database storage/app/database.backup-$(date +%Y%m%d)
```

### If Using MySQL / MariaDB
```bash
cp .env .env.backup-$(date +%Y%m%d)
mysqldump -u root -p docvera > docvera-db-backup-$(date +%Y%m%d).sql
```

*(Note: `storage/app/jobs` and `storage/app/staged` contain ephemeral temporary processing files and do not need long-term archival backups.)*

---

## 20. Upgrade & Rollback Procedure

Docvera uses idempotent, non-destructive schema and file-store migrations (`database/migrations/001_initial_schema.sql` and `002_phase2_saas_platform.sql`) managed by `Docvera\Database\Migrator`.

### Zero-Data-Loss Upgrade Steps
```bash
# 1. Back up .env and database directory
cp .env .env.backup-$(date +%Y%m%d)
cp -r storage/app/database storage/app/database.backup-$(date +%Y%m%d)

# 2. Pull or extract the new release files over the application directory
#    (Note: .env and storage/app/database/* are never included in the release ZIP,
#     so your configuration and data are never overwritten)
unzip -o docvera-v2.0.0.zip -d /var/www/docvera

# 3. Verify host capabilities
php bin/capabilities

# 4. Run pending database migrations
php bin/migrate

# 5. Purge expired staging caches
php bin/cleanup

# 6. Restart background queue worker (if running bin/worker --daemon)
sudo supervisorctl restart docvera-worker
```

### Rollback Procedure
If you ever need to roll back to a previous backup:
```bash
cp .env.backup-YYYYMMDD .env
cp -r storage/app/database.backup-YYYYMMDD/* storage/app/database/
```

---

## 21. Troubleshooting

### 1. A Tool Shows "Server Capability Unavailable"
- **Cause:** The external CLI binary required by that tool (`gs`, `pdfinfo`/`pdftoppm`/`pdftotext`, `soffice`, or `tesseract`) is either not installed on the server or located outside the web server user's `PATH`.
- **Fix:** Run `php bin/capabilities` via SSH. Install the missing package (`sudo apt install ghostscript poppler-utils libreoffice tesseract-ocr`) or specify its full path in `.env` (e.g. `BIN_GS="/usr/local/bin/gs"`), then click **Clear Expired Staging & Temp Cache** in `/admin/updates`.

### 2. Uploads Larger Than 2 MB or 8 MB Fail Immediately
- **Cause:** PHP's default `upload_max_filesize` (`2M`) and `post_max_size` (`8M`) in `php.ini` or Nginx's `client_max_body_size` are lower than your Docvera plan limit.
- **Fix:** Increase `upload_max_filesize = 100M` and `post_max_size = 128M` in `php.ini` and `client_max_body_size 100M;` in Nginx, then reload PHP-FPM and Nginx.

### 3. `/install` Returns "403 Installation is already complete and locked"
- **Cause:** `storage/app/installed.lock` exists, or `APP_INSTALLED=true` is set in `.env`, or at least one user account already exists in the database.
- **Fix:** This is an intentional security lock. Only delete `storage/app/installed.lock` via SSH if you intentionally want to re-run the installer on a fresh instance.

### 4. Asynchronous API Jobs (`?async=1`) Remain in `"queued"` Status
- **Cause:** The CLI queue worker (`bin/worker`) is not running.
- **Fix:** Run `php bin/worker --once` via cron or `php bin/worker --daemon` under Supervisor, or omit `async=1` to use synchronous API execution.

---

## 22. Security Recommendations

1. **Keep `APP_DEBUG="false"` in Production:** Never enable debug mode on an internet-facing server.
2. **Use `public/` as DocumentRoot:** Configure Nginx/Apache so only `public/` is web-accessible.
3. **Serve Over HTTPS (`TLS 1.2+`):** Docvera automatically enables the `Secure` attribute on session cookies (`docvera_sid`) when HTTPS or `X-Forwarded-Proto: https` is detected.
4. **Protect `.env` and `storage/`:** Keep `.env` at mode `0600` and `storage/` directories at mode `0700` owned by the PHP-FPM user.
5. **Run Regular Cleanup:** Ensure `php bin/cleanup` is scheduled in cron every 10 minutes so expired output documents are wiped promptly on schedule.

---

## 23. Frequently Asked Questions (FAQ)

**Q: Does Docvera require MySQL or Redis to run?**  
**A:** No. Out of the box, Docvera uses a concurrency-safe, `flock`-backed JSON persistence store (`DB_CONNECTION="file"`) and synchronous processing (`QUEUE_DRIVER="sync"`). You can switch to `sqlite` or `mysql` at any time in `/install` or `.env`.

**Q: Why does OCR or Word-to-PDF show as "Unavailable" on my server?**  
**A:** Those 4 tools require `libreoffice` (`soffice`) and `tesseract-ocr` to be installed on the host OS. Docvera never fakes conversions; once you run `sudo apt install libreoffice tesseract-ocr`, Docvera automatically detects them and activates those tools.

**Q: Are uploaded customer PDFs ever sent to an external API?**  
**A:** Never. 100% of document inspection, rendering, compression, encryption, and conversion runs locally on your server inside `storage/app/jobs/job_*`.

**Q: Can I use Docvera as an internal company tool with no public access or billing?**  
**A:** Yes. In `/admin/overview`, select **3. PRIVATE INTERNAL WORKSPACE (`private`)** and click Save. All guest access, public registration, billing checkouts, and search indexing will be disabled immediately.

---

## 24. Changelog Summary

- **`v2.0.0` (2026-10-24):** Commercial Marketplace Release — Added Guided Web Installer (`/install`) & CLI Installer (`bin/install`), Multi-Deployment Business Modes (`saas`, `free_ads`, `private`), Configurable Plans & Quota Engine, Stripe & PayPal Subscription Billing with Verified Idempotent Webhooks, Versioned REST API (`/api/v1/*`) with Scoped API Keys & Async Worker (`bin/worker`), 16-Section Admin Console (`/admin/*`), Auth Brute-Force Rate Limiting, and 35-test Automated QA Suite (`bin/test`).
- **`v1.0.0` (2026-01-15):** Initial Workbench Engine — 22 PDF & Document Tools, Homepage Smart File Router, Ghostscript/Poppler/QPDF/NativePHP/LibreOffice/Tesseract Drivers, Magic-Byte Upload Validator, and Ephemeral `0700` Workspace Cleanup.


# Docvera v2.0.0 — Self-Hosted PDF & Document Workbench, SaaS Platform & Developer API

**Docvera** is a privacy-first, self-hosted PHP 8.2+ PDF and document processing platform engineered for commercial SaaS deployment, internal enterprise teams, and public utility sites. All 22 document operations execute locally on your server inside isolated `0700` temporary working directories without requiring paid third-party cloud document APIs.

---

## Documentation Index

- **Complete Operator & Customer Manual (Markdown):** [`docs/DOCUMENTATION.md`](docs/DOCUMENTATION.md)
- **Offline HTML Customer Manual:** [`docs/index.html`](docs/index.html)
- **Release Changelog:** [`CHANGELOG.md`](CHANGELOG.md)
- **Third-Party License Inventory:** [`THIRD_PARTY_LICENSES.md`](THIRD_PARTY_LICENSES.md)

---

## Core Architecture Highlights

- **Guided Web Installer (`/install`) & CLI Installer (`bin/install`):** Inspects required PHP runtime extensions and writable storage directories separately from optional system binaries (`gs`, `qpdf`, `pdftoppm`, `libreoffice`, `tesseract`), configures `.env` with a 256-bit CSPRNG `APP_SECRET`, runs idempotent migrations, seeds default plans, creates the administrator account, and locks `/install` (`HTTP 403`) via `storage/app/database/.installed.lock`.
- **Central Tool Registry & Capability System:** Every tool declares its MIME requirements, file-count limits, option schema, candidate drivers, timeout, and retention policy in [`src/Tools/ToolRegistry.php`](src/Tools/ToolRegistry.php).
- **Live Server Dependency Detection:** [`src/Capabilities/CapabilityDetector.php`](src/Capabilities/CapabilityDetector.php) inspects PHP runtime extensions, storage permissions, database migrations, and system binaries (`gs`, `pdfinfo`, `pdftoppm`, `pdfseparate`, `pdfunite`, `qpdf`, `libreoffice`, `tesseract`). Tools whose external binary is absent on a server automatically degrade to an explicit **Unavailable** diagnostic state rather than failing with an HTTP 500 error.
- **Zero-Shell Subprocess Isolation:** [`src/Security/SafeProcessRunner.php`](src/Security/SafeProcessRunner.php) executes external binaries strictly via `proc_open()` with an argument array (`bypass_shell => true`), enforcing wall-clock timeouts (`PROCESS_TIMEOUT_SECONDS`) and preventing shell command injection.
- **Strict Upload & Magic-Byte Validation:** [`src/Security/UploadValidator.php`](src/Security/UploadValidator.php) inspects files using `finfo` MIME detection and structural header/trailer verification (`%PDF-` and `%%EOF` for PDFs, magic bytes for JPEG/PNG).
- **Update-Safe Database & Storage Layer:** Supports `sqlite` (PDO SQLite), `mysql` (PDO MySQL), and `file` (zero-config atomic flock store) with idempotent schema migrations (`bin/migrate`).
- **Privacy & Ephemeral File Lifecycle:** Input working files are wiped as soon as processing finishes. Output files are protected by session-bound HMAC tokens, can be purged immediately by the user via **Delete File Now**, and are automatically swept by `bin/cleanup` after `RETENTION_TTL_MINUTES`.
- **Multi-Deployment Business Engine:** Switch seamlessly at runtime via `/admin/business-mode` between:
  1. `free_ads` — Public free document-tools site monetized through configurable ad slots (billing disabled).
  2. `saas` — Freemium & Paid SaaS with configurable guest/registered/paid plans, Stripe & PayPal subscriptions, and API monetization.
  3. `private` — Authenticated internal company/team document workbench (public guest tools and open registration disabled).
- **Authoritative Plan & Quota Engine:** Central [`src/Entitlements/EntitlementService.php`](src/Entitlements/EntitlementService.php) enforces daily/monthly operations, max file size, max files per operation, max PDF pages, concurrent job slots, OCR/batch allowances, tool access lists, API quotas, and retention windows across both Web UI and REST API.
- **Versioned REST API (`/api/v1/*`) & Auto-Generated Docs:** Exposes all 22 API-capable tools from `ToolRegistry` without duplicating processing logic. Supports SHA-256 hashed `dcv_live_...` API keys, granular scopes (`tools:execute`, `jobs:read`, `jobs:download`, `jobs:delete`), per-minute rate limiting, `Idempotency-Key` replay, synchronous & asynchronous (`async=1` + `bin/worker`) execution, and live documentation at `/developers/api` and `/api/v1/openapi.json`.

---

## Complete Tool Matrix (22 Tools)

### Priority Group A (12 Core Tools)
| Tool ID | Tool Name | Primary Drivers | Description |
| :--- | :--- | :--- | :--- |
| `merge-pdf` | **Merge PDF** | `qpdf` → `poppler` → `ghostscript` | Combine 2 or more PDF files in exact user sequence |
| `split-pdf` | **Split PDF** | `poppler` → `ghostscript` | Split every page or every $N$ pages into separate PDFs (ZIP) |
| `extract-pages` | **Extract Pages** | `ghostscript` | Extract specific pages or ranges (`1, 3-5`) into a new PDF |
| `delete-pages` | **Delete Pages** | `ghostscript` | Remove selected pages from a PDF and save remaining pages |
| `reorder-pages` | **Reorder Pages** | `poppler` → `ghostscript` | Rearrange PDF pages into any custom sequence |
| `rotate-pages` | **Rotate Pages** | `qpdf` → `ghostscript` | Rotate all or selected pages by 90°, 180°, or 270° |
| `images-to-pdf` | **Images to PDF** | `native_php_pdf` | Compile JPG and PNG images into A4, Letter, or Fit-to-Image PDFs |
| `pdf-to-images` | **PDF to JPG / PNG** | `poppler` (`pdftoppm`) | Render PDF pages to PNG or JPG at 72, 150, or 300 DPI |
| `compress-pdf` | **Compress PDF** | `ghostscript` | Optimize PDF streams and images (`screen`, `ebook`, `printer`) |
| `protect-pdf` | **Password Protect PDF** | `qpdf` → `ghostscript` | Encrypt PDF with open password and owner permissions password |
| `unlock-pdf` | **Unlock PDF** | `qpdf` → `ghostscript` | Decrypt a password-protected PDF using the known password |
| `watermark-pdf` | **Watermark PDF** | `ghostscript` | Stamp vector text watermarks across all PDF pages |

### Priority Group B (10 Extended Tools)
| Tool ID | Tool Name | Primary Drivers | Description |
| :--- | :--- | :--- | :--- |
| `crop-pdf` | **Crop PDF** | `ghostscript` | Trim top, right, bottom, and left page margins in points |
| `page-numbers` | **Add Page Numbers** | `ghostscript` | Stamp formatted page numbers (`Page 1 of N`, `1`) in header/footer |
| `pdf-metadata` | **PDF Metadata View & Edit** | `poppler` + `ghostscript` | Inspect and update Title, Author, Subject, Keywords, and Creator |
| `grayscale-pdf` | **Grayscale PDF** | `ghostscript` | Convert color PDFs to monochrome `DeviceGray` |
| `flatten-pdf` | **Flatten PDF** | `ghostscript` | Flatten interactive annotations/forms or rasterize pages |
| `text-to-pdf` | **Text to PDF** | `native_php_pdf` | Format `.txt` files or direct text into paginated PDFs |
| `ocr-pdf` | **OCR Scanned PDF / Image** | `tesseract` | Extract text or generate searchable PDFs via Tesseract OCR |
| `word-to-pdf` | **Word to PDF** | `libreoffice` | Convert `.docx` / `.doc` to PDF via headless LibreOffice |
| `excel-to-pdf` | **Excel to PDF** | `libreoffice` | Convert `.xlsx` / `.xls` spreadsheets to PDF |
| `powerpoint-to-pdf` | **PowerPoint to PDF** | `libreoffice` | Convert `.pptx` / `.ppt` presentations to PDF |

---

## System Requirements & Optional Server Dependencies

### Required Core Environment
- **PHP:** `>= 8.2` (`8.2` or `8.3` recommended)
- **PHP Extensions:** `fileinfo`, `zip`, `mbstring`, `openssl`, `json`
- **Writable Directories:** `storage/app/jobs`, `storage/app/staged`, `storage/app/database`, `storage/logs`

### Recommended External System Binaries (Auto-Detected)
Docvera does not bundle third-party binaries; instead, it detects installed packages on your host OS:

```bash
# Ubuntu / Debian
sudo apt update
sudo apt install -y ghostscript poppler-utils qpdf libreoffice-core libreoffice-writer libreoffice-calc libreoffice-impress tesseract-ocr
```

---

## Installation & Quick Start

### Option A — Guided Web Installer
1. Point your web server's document root to `public/` (or run `php -S 127.0.0.1:8080 -t public public/index.php`).
2. Open `http://127.0.0.1:8080/install` in your browser.
3. Review the preflight checks, configure your database and admin credentials, and click **Run Installation & Lock Installer**.

### Option B — CLI Installer
1. **Run Preflight Check:**
   ```bash
   php bin/install --check-only
   ```
2. **Execute Non-Interactive Install:**
   ```bash
   php bin/install \
     --app-name="Docvera" \
     --app-url="http://127.0.0.1:8080" \
     --business-mode="saas" \
     --db-driver="sqlite" \
     --admin-name="System Administrator" \
     --admin-email="admin@example.com" \
     --admin-password="ChangeMeImmediately!2026"
   ```
3. **Inspect Detected Server Capabilities:**
   ```bash
   php bin/capabilities
   ```
4. **Run Automated Verification & Fixture Test Suite (35 Checks):**
   ```bash
   php bin/test
   ```
5. **Configure Scheduled Cleanup & Async Queue Worker (Cron):**
   ```cron
   */15 * * * * /usr/bin/php /path/to/docvera/bin/cleanup >> /dev/null 2>&1
   * * * * * /usr/bin/php /path/to/docvera/bin/worker --limit=25 >> /dev/null 2>&1
   ```

---

## Safe Upgrade & Rollback Procedure

1. **Backup Configuration & Database:**
   ```bash
   cp .env .env.backup
   cp -r storage/app/database storage/app/database.backup-$(date +%Y%m%d)
   ```
2. **Pre-Upgrade Capability Check:**
   ```bash
   php bin/capabilities
   ```
3. **Apply Idempotent Schema Migrations:**
   ```bash
   php bin/migrate
   ```
4. **Run Verification Suite:**
   ```bash
   php bin/test
   ```
5. **Rollback (if required):** Restore `storage/app/database.backup-YYYYMMDD` and previous release files.

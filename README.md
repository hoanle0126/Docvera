# Docvera

**Privacy-First, Self-Hosted Document Processing Platform & REST API**

[![License: Apache-2.0](https://img.shields.io/badge/License-Apache--2.0-blue.svg)](LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-4F5B93.svg)](composer.json)
[![OpenAPI 3.1](https://img.shields.io/badge/OpenAPI-3.1.0-6BA539.svg)](src/Api/ApiDocGenerator.php)

Docvera is an open-source, self-hosted PDF and document workbench, multi-tenant platform, and versioned REST API (`/api/v1/*`). It processes every document **locally on your infrastructure** inside isolated `0700` per-job workspaces using standard Linux CLI engines (`qpdf`, `poppler-utils`, `ghostscript`, `libreoffice`, `tesseract-ocr`) and native PHP extensions (`gd`, `zip`, `fileinfo`)—with **zero external cloud processing calls** and **zero telemetry**.

![Docvera Workbench Overview](docs/screenshots/01-homepage-workbench.png)

---

## Why Docvera?

- **100% On-Premise Document Privacy:** Uploaded files are validated by magic bytes, processed in isolated `storage/app/jobs/job_<32hex>/{input,work,output}` directories (`0700`), and have their `input/` and `work/` directories purged immediately inside a `finally` block as soon as processing completes.
- **Unified Web Workbench & REST API:** All 22 document tools run through the exact same `ToolRegistry → UploadValidator → ToolExecutionService → ProcessingDriver` pipeline whether invoked from the browser or via `POST /api/v1/*`.
- **Truthful Capability Detection & Automatic Driver Fallback:** Docvera probes host binaries at runtime (`php bin/capabilities`, `GET /api/v1/capabilities`). Tools with multiple candidate drivers (`qpdf → poppler → ghostscript`) automatically fall back if a primary binary is absent, and optional tools (`libreoffice`, `tesseract`) report clear remediation instructions rather than crashing at runtime.
- **Zero-Bloat Stack:** Written in strict PHP 8.2+ with zero external Composer runtime packages and zero heavy frontend JS frameworks. Runs out of the box with an atomic `flock` JSON store, SQLite (WAL mode), or MySQL.

---

## Complete Tool & Driver Matrix (22 Tools)

| Category | Tool Slug | REST API Endpoint | Primary & Fallback Drivers | Output Format | Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Organize** | `merge-pdf` | `POST /api/v1/pdf/merge` | `qpdf → poppler → ghostscript` | `application/pdf` | `STABLE` |
| **Organize** | `split-pdf` | `POST /api/v1/pdf/split` | `poppler → ghostscript` | `application/zip` (PDFs) | `STABLE` |
| **Organize** | `extract-pages` | `POST /api/v1/pdf/extract-pages` | `qpdf → ghostscript` | `application/pdf` | `STABLE` |
| **Organize** | `delete-pages` | `POST /api/v1/pdf/delete-pages` | `qpdf → ghostscript` | `application/pdf` | `STABLE` |
| **Organize** | `reorder-pages` | `POST /api/v1/pdf/reorder-pages` | `qpdf → ghostscript` | `application/pdf` | `STABLE` |
| **Organize** | `rotate-pages` | `POST /api/v1/pdf/rotate` | `qpdf → ghostscript` | `application/pdf` | `STABLE` |
| **Convert to PDF** | `images-to-pdf` | `POST /api/v1/convert/images-to-pdf` | `native_php` (`ext-gd`) | `application/pdf` | `STABLE` |
| **Convert to PDF** | `text-to-pdf` | `POST /api/v1/convert/text-to-pdf` | `native_php` | `application/pdf` | `STABLE` |
| **Convert to PDF** | `word-to-pdf` | `POST /api/v1/convert/word-to-pdf` | `libreoffice` (`soffice`) | `application/pdf` | `STABLE` *(Requires LibreOffice)* |
| **Convert to PDF** | `excel-to-pdf` | `POST /api/v1/convert/excel-to-pdf` | `libreoffice` (`soffice`) | `application/pdf` | `STABLE` *(Requires LibreOffice)* |
| **Convert to PDF** | `powerpoint-to-pdf` | `POST /api/v1/convert/powerpoint-to-pdf` | `libreoffice` (`soffice`) | `application/pdf` | `STABLE` *(Requires LibreOffice)* |
| **Convert from PDF** | `pdf-to-images` | `POST /api/v1/convert/pdf-to-images` | `poppler → ghostscript` | `image/png`, `image/jpeg`, or `application/zip` | `STABLE` |
| **Convert from PDF** | `ocr-pdf` | `POST /api/v1/pdf/ocr` | `tesseract` (+ `pdftoppm`/`gs` for PDFs) | `application/pdf` or `text/plain` | `STABLE` *(Requires Tesseract)* |
| **Optimize & Stamp** | `compress-pdf` | `POST /api/v1/pdf/compress` | `ghostscript` | `application/pdf` | `STABLE` |
| **Optimize & Stamp** | `grayscale-pdf` | `POST /api/v1/pdf/grayscale` | `ghostscript` | `application/pdf` | `STABLE` |
| **Optimize & Stamp** | `flatten-pdf` | `POST /api/v1/pdf/flatten` | `ghostscript → qpdf` | `application/pdf` | `STABLE` |
| **Optimize & Stamp** | `watermark-pdf` | `POST /api/v1/pdf/watermark` | `ghostscript` | `application/pdf` | `STABLE` |
| **Optimize & Stamp** | `page-numbers` | `POST /api/v1/pdf/page-numbers` | `ghostscript` | `application/pdf` | `STABLE` |
| **Optimize & Stamp** | `crop-pdf` | `POST /api/v1/pdf/crop` | `ghostscript` | `application/pdf` | `STABLE` |
| **Security & Metadata** | `protect-pdf` | `POST /api/v1/pdf/protect` | `qpdf → ghostscript` | `application/pdf` (AES-256) | `STABLE` |
| **Security & Metadata** | `unlock-pdf` | `POST /api/v1/pdf/unlock` | `qpdf → ghostscript` | `application/pdf` | `STABLE` |
| **Security & Metadata** | `pdf-metadata` | `POST /api/v1/pdf/metadata` | `ghostscript` | `application/pdf` | `STABLE` |

*(Every tool can also be called via the uniform route `POST /api/v1/tools/{slug}`.)*

---

## Quickstart

### Option A: Docker Compose (Recommended — All 22 Tools Active)

The included [`Dockerfile`](Dockerfile) and [`docker-compose.yml`](docker-compose.yml) bundle PHP 8.3 together with `qpdf`, `poppler-utils`, `ghostscript`, `libreoffice`, and `tesseract-ocr`, plus background containers for the async queue worker and TTL cleanup service:

```bash
git clone https://github.com/hoanle0126/Docvera.git
cd Docvera
docker compose up -d --build
```

Open `http://localhost:8080/install` to complete the 60-second setup wizard, or run the CLI installer inside the container:

```bash
docker compose exec docvera php bin/install \
  --app-name="Docvera" \
  --app-url="http://localhost:8080" \
  --business-mode="private" \
  --db-driver="sqlite" \
  --admin-email="admin@example.com" \
  --admin-password="ChangeMeStrongPassword123!"
```

### Option B: Bare-Metal / Local PHP 8.2+ Setup

```bash
# 1. Install PHP extensions and native document tools (Ubuntu/Debian example)
sudo apt-get update && sudo apt-get install -y \
  php-cli php-gd php-mbstring php-sqlite3 php-xml php-zip \
  ghostscript poppler-utils qpdf libreoffice tesseract-ocr

# 2. Clone the repository
git clone https://github.com/hoanle0126/Docvera.git
cd Docvera

# 3. Inspect detected host capabilities and active tools
php bin/capabilities

# 4. Run the automated verification suite
php bin/test

# 5. Start the development server
php -S 127.0.0.1:8080 -t public public/index.php
```

---

## REST API & Automation (`/api/v1/*`)

Generate a scoped API key (`dcv_live_...`) from `/dashboard` and automate document workflows over HTTP.

### 1. Check Server Health & Capabilities
```bash
curl -s http://127.0.0.1:8080/api/v1/health | jq .
```

### 2. Synchronous Execution (`200 OK`)
```bash
curl -X POST "http://127.0.0.1:8080/api/v1/pdf/compress" \
  -H "Authorization: Bearer dcv_live_YOUR_API_KEY" \
  -H "Idempotency-Key: job-2026-0001" \
  -F "files[]=@/path/to/report.pdf" \
  -F "compression_level=ebook"
```

### 3. Asynchronous Queue Execution (`202 Accepted`)
Pass `-F "async=1"` or `-H "Prefer: respond-async"` to enqueue heavy OCR or multi-file batch jobs for `php bin/worker`:

```bash
curl -X POST "http://127.0.0.1:8080/api/v1/pdf/ocr" \
  -H "Authorization: Bearer dcv_live_YOUR_API_KEY" \
  -F "files[]=@/path/to/scanned.pdf" \
  -F "output_format=pdf" \
  -F "async=1"
```

### 4. Poll Status, Download Artifact, or Purge Immediately
```bash
# Poll status
curl -H "Authorization: Bearer dcv_live_YOUR_API_KEY" \
  "http://127.0.0.1:8080/api/v1/jobs/job_0123456789abcdef0123456789abcdef"

# Download processed document
curl -L -H "Authorization: Bearer dcv_live_YOUR_API_KEY" \
  "http://127.0.0.1:8080/api/v1/jobs/job_0123456789abcdef0123456789abcdef/download" \
  -o output.pdf

# Immediately purge files from server storage
curl -X DELETE -H "Authorization: Bearer dcv_live_YOUR_API_KEY" \
  "http://127.0.0.1:8080/api/v1/jobs/job_0123456789abcdef0123456789abcdef"
```

---

## Security & Privacy Model

Docvera is built around hostile-input assumptions:
- **Zero Shell Interpolation:** External binaries are executed exclusively via `SafeProcessRunner` (`proc_open` with discrete argument arrays, `bypass_shell => true`, sanitized environment variables, 1 MB output buffer caps, and process-group kill on timeout).
- **Magic-Byte & OpenXML Bomb Inspection:** `UploadValidator` verifies `finfo_file()` MIME types, magic headers (`%PDF-`, image signatures, OLE2 headers), and inspects `.docx`/`.xlsx`/`.pptx` ZIP containers for path traversal (`..`), entry count ceilings (`<= 2,500`), uncompressed size ceilings (`<= 250 MB`), and compression ratio limits (`<= 100:1`).
- **Immediate Input & Scratch Wipe:** Uploaded source files (`input/`) and scratch files (`work/`) are deleted inside a `finally` block immediately after the tool finishes. Sensitive options (such as PDF passwords) are scrubbed from persisted job records.
- **Three Deployment Modes:** Run as a **Private Internal Workspace** (`private` — authentication required for all routes, `noindex` enforced), a **Free Public Utility** (`free_ads`), or a **Quota-Governed Multi-User Service** (`saas`).

---

## Interface Previews

| Tool Workbench (`/tools/merge-pdf`) | Developer REST API Reference (`/developers/api`) |
| :---: | :---: |
| ![Merge PDF Workbench](docs/screenshots/02-merge-pdf-tool.png) | ![REST API Documentation](docs/screenshots/03-developer-rest-api.png) |

| Administration & Capability Console (`/admin`) | Guided Web Installer (`/install`) |
| :---: | :---: |
| ![Admin Console](docs/screenshots/05-admin-console.png) | ![Guided Web Installer](docs/screenshots/06-web-installer.png) |

---

## Documentation & Community

- [**System Architecture (`docs/ARCHITECTURE.md`)**](docs/ARCHITECTURE.md) — Pipeline design, security boundaries, and guide to adding new tools or drivers.
- [**Operator & API Manual (`docs/DOCUMENTATION.md`)**](docs/DOCUMENTATION.md) — Full configuration, Nginx/Apache/Docker deployment, quotas, and API reference.
- [**Contributing Guide (`CONTRIBUTING.md`)**](CONTRIBUTING.md) — Development setup, coding standards, and pull request checklist.
- [**Security Policy (`SECURITY.md`)**](SECURITY.md) — Threat model and vulnerability disclosure policy.
- [**Public Roadmap (`ROADMAP.md`)**](ROADMAP.md) — Planned milestones (`v2.1+`).
- [**Third-Party Licenses (`THIRD_PARTY_LICENSES.md`)**](THIRD_PARTY_LICENSES.md) — Host binary and runtime license notes.

---

## License

Docvera is open-source software licensed under the [**Apache License 2.0**](LICENSE).
